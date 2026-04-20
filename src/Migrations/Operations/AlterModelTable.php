<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

/**
 * Operation to rename a model's database table.
 *
 * This operation changes the db_table option for a model, which affects
 * the underlying database table name that the model uses.
 * 
 * Django-compatible: follows AlterModelTable operation semantics.
 */
class AlterModelTable extends Operation
{
    /**
     * @param string $name The model name to alter
     * @param string|null $table The new db_table value, or null to remove custom table name
     */
    public function __construct(
        public string $name,
        public ?string $table = null
    ) {}

    /**
     * Mutate the project state to reflect the table name change.
     */
    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->name);
        if ($table) {
            $options = $table->options;
            if ($this->table === null) {
                unset($options['db_table']);
            } else {
                $options['db_table'] = $this->table;
            }
            $table->options = $options;
        }
    }

    /**
     * Apply the table rename to the database.
     * Uses fromState to get old db_table and toState to get new db_table.
     */
    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Get the table from toState (after the change) to get both old and new db_table
        $newTable = $toState->getTable($this->name);
        if (!$newTable) return;
        
        // Old db_table from fromState
        $oldTable = $fromState->getTable($this->name);
        $oldDbTable = $oldTable?->options['db_table'] ?? $this->name;
        $newDbTable = $newTable->options['db_table'] ?? $this->name;

        if ($oldDbTable !== $newDbTable) {
            $schemaEditor->renameTable($oldDbTable, $newDbTable);
        }
    }

    /**
     * Reverse the table rename in the database.
     */
    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Convention in this codebase: during unapply, databaseBackwards receives
        // (fromState = state before operation, toState = state after operation).
        $oldTable = $fromState->getTable($this->name);
        $newTable = $toState->getTable($this->name);

        if (!$oldTable || !$newTable) {
            return;
        }

        $oldDbTable = $oldTable->options['db_table'] ?? $this->name;
        $newDbTable = $newTable->options['db_table'] ?? $this->name;

        if ($oldDbTable !== $newDbTable) {
            $schemaEditor->renameTable($newDbTable, $oldDbTable);
        }
    }

    /**
     * Return a human-readable description of this operation.
     *
     * @return string The operation description
     */
    public function describe(): string
    {
        if ($this->table === null) {
            return "Remove custom table name for {$this->name}";
        }
        return "Rename table for {$this->name} to {$this->table}";
    }

    /**
     * Deconstruct this operation for serialization.
     *
     * @return array [className, constructorArguments]
     */
    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                'name' => $this->name,
                'table' => $this->table
            ]
        ];
    }
}
