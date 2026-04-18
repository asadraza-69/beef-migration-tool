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
 */
class AlterModelTable extends Operation
{
    /**
     * @param string $name The model name (table name) to alter
     * @param string|null $table The new table name, or null to remove custom table name
     */
    public function __construct(
        public string $name,
        public ?string $table = null
    ) {}

    /**
     * Mutate the project state to reflect the table name change.
     *
     * @param ProjectState $state The current project state
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
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation
     * @param ProjectState $toState The state after this operation
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $oldTable = $fromState->getTable($this->name);
        $newTable = $toState->getTable($this->name);

        if ($oldTable && $newTable) {
            $oldName = $oldTable->options['db_table'] ?? $this->name;
            $newName = $newTable->options['db_table'] ?? $this->name;

            if ($oldName !== $newName) {
                $schemaEditor->renameTable($oldName, $newName);
            }
        }
    }

    /**
     * Reverse the table rename in the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation (forward state)
     * @param ProjectState $toState The state after this operation (backward state)
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // The operation is reversible - just swap the direction
        $this->databaseForwards($schemaEditor, $toState, $fromState, $registry);
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