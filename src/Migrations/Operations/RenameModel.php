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

            // Keep the db_table option aligned when it simply mirrors the model key.
            // This avoids self-renames like RENAME TABLE post -> post when db_table
            // is redundantly stored as the table name (common in current Inspector output).
            if (($table->options['db_table'] ?? null) === $this->oldName) {
                $table->options['db_table'] = $this->newName;
            }

            $state->addTable($table);
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $oldTable = $fromState->getTable($this->oldName);
        $newTable = $toState->getTable($this->newName);
        
        $oldDbName = $oldTable?->options['db_table'] ?? $this->oldName;
        $newDbName = $newTable?->options['db_table'] ?? $this->newName;

        // Django parity: if the underlying db_table is unchanged (e.g. explicit db_table),
        // a RenameModel should not try to rename the database table.
        if ($oldDbName === $newDbName) {
            return;
        }
        
        $schemaEditor->renameTable($oldDbName, $newDbName);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Convention in this codebase: during unapply, databaseBackwards receives
        // (fromState = state before operation, toState = state after operation).
        $oldTable = $fromState->getTable($this->oldName);
        $newTable = $toState->getTable($this->newName);
        
        $oldDbName = $oldTable?->options['db_table'] ?? $this->oldName;
        $newDbName = $newTable?->options['db_table'] ?? $this->newName;

        if ($newDbName === $oldDbName) {
            return;
        }
        
        $schemaEditor->renameTable($newDbName, $oldDbName);
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
