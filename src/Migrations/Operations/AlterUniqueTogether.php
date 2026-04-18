<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

/**
 * Operation to change the unique_together option on a model.
 *
 * This operation sets the unique constraints (UNIQUE constraints) on a model,
 * specifying combinations of fields that must be unique together.
 */
class AlterUniqueTogether extends Operation
{
    /**
     * @param string $name The model name to alter
     * @param array $uniqueTogether Array of field name arrays that define unique constraints,
     *                               e.g., [['field1', 'field2'], ['field3', 'field4']]
     */
    public function __construct(
        public string $name,
        public array $uniqueTogether = []
    ) {}

    /**
     * Mutate the project state to reflect the unique_together change.
     *
     * @param ProjectState $state The current project state
     */
    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->name);
        if ($table) {
            $options = $table->options;
            // Normalize to array of arrays format
            $normalized = [];
            foreach ($this->uniqueTogether as $constraint) {
                if (is_array($constraint)) {
                    $normalized[] = $constraint;
                } else {
                    $normalized[] = [$constraint];
                }
            }
            $options['unique_together'] = $normalized;
            $table->options = $options;
        }
    }

    /**
     * Apply the unique_together change to the database.
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

        if (!$oldTable || !$newTable) {
            return;
        }

        $oldUnique = $oldTable->options['unique_together'] ?? [];
        $newUnique = $newTable->options['unique_together'] ?? [];

        // Normalize both to comparable format
        $oldSet = array_map('serialize', $oldUnique);
        $newSet = array_map('serialize', $newUnique);

        // Remove unique constraints that are no longer in new
        foreach ($oldUnique as $oldConstraint) {
            if (!in_array(serialize($oldConstraint), $newSet)) {
                $schemaEditor->alterUniqueTogether(
                    $this->name,
                    $oldConstraint,
                    []
                );
            }
        }

        // Add unique constraints that are new
        foreach ($newUnique as $newConstraint) {
            if (!in_array(serialize($newConstraint), $oldSet)) {
                $schemaEditor->alterUniqueTogether(
                    $this->name,
                    [],
                    $newConstraint
                );
            }
        }
    }

    /**
     * Reverse the unique_together change in the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation (forward state)
     * @param ProjectState $toState The state after this operation (backward state)
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Swap the direction - apply from toState to fromState
        $this->databaseForwards($schemaEditor, $toState, $fromState, $registry);
    }

    /**
     * Return a human-readable description of this operation.
     *
     * @return string The operation description
     */
    public function describe(): string
    {
        $count = count($this->uniqueTogether);
        return "Alter unique_together for {$this->name} ({$count} constraint(s))";
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
                'uniqueTogether' => $this->uniqueTogether
            ]
        ];
    }
}