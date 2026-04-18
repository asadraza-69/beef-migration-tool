<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

/**
 * Operation to change the managers on a model.
 *
 * This operation allows adding, removing, or changing custom model managers
 * that provide database query interfaces for the model.
 */
class AlterModelManagers extends Operation
{
    /**
     * @param string $name The model name to alter
     * @param array $managers Array of manager configurations, each as ['name' => string, 'class' => string]
     */
    public function __construct(
        public string $name,
        public array $managers = []
    ) {}

    /**
     * Mutate the project state to reflect the managers change.
     *
     * @param ProjectState $state The current project state
     */
    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->name);
        if ($table) {
            $table->options['managers'] = $this->managers;
        }
    }

    /**
     * Apply the managers change to the database.
     *
     * AlterModelManagers doesn't affect the database schema - the change is only
     * at the ORM level. This is a no-op at the database level.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation
     * @param ProjectState $toState The state after this operation
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // This operation only affects the in-memory state, not the database
    }

    /**
     * Reverse the managers change in the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation (forward state)
     * @param ProjectState $toState The state after this operation (backward state)
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // This operation only affects the in-memory state
    }

    /**
     * Return a human-readable description of this operation.
     *
     * @return string The operation description
     */
    public function describe(): string
    {
        $managerNames = array_map(fn($m) => $m['name'] ?? 'unknown', $this->managers);
        return sprintf(
            "Change managers on %s: %s",
            $this->name,
            implode(', ', $managerNames)
        );
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
                'managers' => $this->managers
            ]
        ];
    }
}