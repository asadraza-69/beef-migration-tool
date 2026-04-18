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
            $column = $table->getColumn($this->oldName);
            if ($column) {
                $table->removeColumn($this->oldName);
                $column->name = $this->newName;
                $table->addColumn($column);
            }
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $table = $toState->getTable($this->modelName);
        $column = $table->getColumn($this->newName);
        $schemaEditor->renameColumn($this->modelName, $this->oldName, $column);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $table = $fromState->getTable($this->modelName);
        $column = $table->getColumn($this->oldName);
        $schemaEditor->renameColumn($this->modelName, $this->newName, $column);
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
