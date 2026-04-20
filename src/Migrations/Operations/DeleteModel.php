<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

class DeleteModel extends Operation
{
    public function __construct(
        public string $name
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $state->removeTable($this->name);
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($schemaEditor->tableExists($this->name)) {
            $schemaEditor->deleteTable($this->name);
        }
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $table = $fromState->getTable($this->name);
        if ($table && !$schemaEditor->tableExists($this->name)) {
            $dbName = $table->options['db_table'] ?? $this->name;
            $schemaEditor->createTable($table, $dbName);
        }
    }

    public function describe(): string
    {
        return "Delete model {$this->name}";
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [$this->name]
        ];
    }
}
