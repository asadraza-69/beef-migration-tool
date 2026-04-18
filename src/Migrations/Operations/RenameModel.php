<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

class RenameModel extends Operation
{
    public function __construct(
        public string $oldName,
        public string $newName
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->oldName);
        if ($table) {
            $state->removeTable($this->oldName);
            $table->name = $this->newName;
            $state->addTable($table);
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $oldTable = $fromState->getTable($this->oldName);
        $newTable = $toState->getTable($this->newName);
        
        $oldDbName = $oldTable?->options['db_table'] ?? $this->oldName;
        $newDbName = $newTable?->options['db_table'] ?? $this->newName;
        
        if ($oldDbName !== $newDbName) {
            $schemaEditor->renameTable($oldDbName, $newDbName);
        }
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $newTable = $fromState->getTable($this->newName);
        $oldTable = $toState->getTable($this->oldName);
        
        $newDbName = $newTable?->options['db_table'] ?? $this->newName;
        $oldDbName = $oldTable?->options['db_table'] ?? $this->oldName;
        
        if ($oldDbName !== $newDbName) {
            $schemaEditor->renameTable($newDbName, $oldDbName);
        }
    }

    public function describe(): string
    {
        return sprintf("Rename model '%s' to '%s'", $this->oldName, $this->newName);
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->oldName,
                $this->newName
            ]
        ];
    }
}
