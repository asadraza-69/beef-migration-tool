<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

class RenameField extends Operation
{
    public function __construct(
        public string $modelName,
        public string $oldName,
        public string $newName
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if ($table) {
            // Try old name first, then new name (in case already renamed)
            $column = $table->getColumn($this->oldName) ?? $table->getColumn($this->newName);
            if ($column) {
                $table->removeColumn($column->name);
                $column->name = $this->newName;
                $table->addColumn($column);
            }
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $table = $toState->getTable($this->modelName);
        if (!$table) {
            return;
        }

        // The "to" state already has the new column name (stateForwards runs first).
        // For the database rename we must rename oldName -> newName.
        $newColumn = $table->getColumn($this->newName) ?? $table->getColumn($this->oldName);
        if (!$newColumn) {
            return;
        }

        if (!$schemaEditor->tableExists($this->modelName)) {
            return;
        }

        // If the database already has the new column name (and not the old one), treat this as already applied.
        if (
            $schemaEditor->columnExists($this->modelName, $this->newName)
            && !$schemaEditor->columnExists($this->modelName, $this->oldName)
        ) {
            return;
        }

        if (!$schemaEditor->columnExists($this->modelName, $this->oldName)) {
            // Nothing to rename (avoid throwing a DB error); keep state change only.
            return;
        }

        $schemaEditor->renameColumn($this->modelName, $this->oldName, $newColumn);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Convention in this codebase: during unapply, databaseBackwards receives
        // (fromState = state before operation, toState = state after operation).
        // We need to rename the column from newName back to oldName using the
        // column definition from the "after" state.
        $table = $toState->getTable($this->modelName);
        if (!$table) {
            return;
        }

        $column = $table->getColumn($this->newName) ?? $table->getColumn($this->oldName);
        if (!$column) {
            return;
        }

        if ($column->name === $this->oldName) {
            return;
        }

        $oldColumn = clone $column;
        $oldColumn->name = $this->oldName;
        $schemaEditor->renameColumn($this->modelName, $column->name, $oldColumn);
    }

    public function describe(): string
    {
        return sprintf("Rename field '%s' on %s to '%s'", $this->oldName, $this->modelName, $this->newName);
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->modelName,
                $this->oldName,
                $this->newName
            ]
        ];
    }
}
