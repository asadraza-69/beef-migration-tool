<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

class RemoveForeignKey extends Operation
{
    public function __construct(
        public string $modelName,
        public string $name,
        public ?string $column = null,
        public ?string $toModel = null,
        public ?string $toColumn = null,
        public string $onDelete = 'CASCADE'
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if ($table) {
            // Prefer removing by exact name, but allow column-based fallback so that
            // migrations remain reversible even if the constraint name changes.
            if (isset($table->getForeignKeys()[$this->name])) {
                $table->removeForeignKey($this->name);
                return;
            }

            $column = $this->column ?? $this->inferColumnFromConstraintName($this->modelName, $this->name);
            if ($column === null) {
                return;
            }

            foreach ($table->getForeignKeys() as $fkName => $fk) {
                if ($fk->column === $column) {
                    $table->removeForeignKey($fkName);
                    return;
                }
            }
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($schemaEditor->tableExists($this->modelName) && $schemaEditor->foreignKeyExists($this->modelName, $this->name)) {
            $schemaEditor->removeForeignKey($this->modelName, $this->name);
        }
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if (!$schemaEditor->tableExists($this->modelName)) {
            return;
        }

        $column = $this->column;
        $toModel = $this->toModel;
        $toColumn = $this->toColumn;
        $onDelete = $this->onDelete;
        $fkName = $this->name;

        $oldTable = $fromState->getTable($this->modelName);
        $oldFk = null;
        if ($oldTable) {
            $oldFk = $oldTable->getForeignKeys()[$this->name] ?? null;

            if ($oldFk === null) {
                $matchColumn = $column ?? $this->inferColumnFromConstraintName($this->modelName, $this->name);
                if ($matchColumn !== null) {
                    foreach ($oldTable->getForeignKeys() as $candidate) {
                        if ($candidate->column === $matchColumn) {
                            $oldFk = $candidate;
                            break;
                        }
                    }
                }
            }
        }

        if ($oldFk) {
            $fkName = $oldFk->name;
            $column = $column ?? $oldFk->column;
            $toModel = $toModel ?? $oldFk->toTable;
            $toColumn = $toColumn ?? $oldFk->toColumn;
            $onDelete = $oldFk->onDelete;
        }

        // Only add when the constraint isn't present already (by either name).
        if ($schemaEditor->foreignKeyExists($this->modelName, $this->name) || $schemaEditor->foreignKeyExists($this->modelName, $fkName)) {
            return;
        }

        if ($column !== null && $toModel !== null && $toColumn !== null) {
            $schemaEditor->addForeignKey($this->modelName, $column, $toModel, $toColumn, $onDelete, $fkName);
        }
    }

    public function describe(): string
    {
        return "Remove foreign key {$this->name} from {$this->modelName}";
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->modelName,
                $this->name
            ]
        ];
    }

    private function inferColumnFromConstraintName(string $tableName, string $constraintName): ?string
    {
        $prefix = "fk_{$tableName}_";
        if (str_starts_with($constraintName, $prefix)) {
            $suffix = substr($constraintName, strlen($prefix));
            return $suffix === '' ? null : $suffix;
        }
        return null;
    }
}
