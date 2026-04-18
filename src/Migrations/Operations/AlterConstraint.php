<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

/**
 * Operation to alter an existing constraint on a model.
 *
 * This operation modifies an existing database constraint (e.g., changes
 * the check expression or other constraint properties).
 */
class AlterConstraint extends Operation
{
    /**
     * @param string $modelName The model name (table name) to alter
     * @param string $name The name of the constraint to alter
     * @param object $constraint The new constraint definition
     */
    public function __construct(
        public string $modelName,
        public string $name,
        public object $constraint
    ) {}

    /**
     * Mutate the project state to reflect the constraint alteration.
     *
     * @param ProjectState $state The current project state
     */
    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if (!$table) {
            return;
        }

        $constraints = $table->getConstraints();
        
        // Replace the old constraint with the new one
        if (isset($constraints[$this->name])) {
            $constraints[$this->name] = $this->constraint;
            
            // Update the table's constraints
            $table->options['constraints'] = array_values($constraints);
        }
    }

    /**
     * Apply the constraint alteration to the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation
     * @param ProjectState $toState The state after this operation
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Remove old constraint and add new one
        $schemaEditor->removeConstraint($this->modelName, $this->name);
        
        $newConstraint = $toState->getTable($this->modelName)?->getConstraints()[$this->name] ?? $this->constraint;
        if ($newConstraint) {
            $schemaEditor->addConstraint($this->modelName, $newConstraint);
        }
    }

    /**
     * Reverse the constraint alteration in the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation (forward state)
     * @param ProjectState $toState The state after this operation (backward state)
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Reverse is the same - swap the constraints
        $this->databaseForwards($schemaEditor, $toState, $fromState, $registry);
    }

    /**
     * Return a human-readable description of this operation.
     *
     * @return string The operation description
     */
    public function describe(): string
    {
        return "Alter constraint {$this->name} on {$this->modelName}";
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
                'modelName' => $this->modelName,
                'name' => $this->name,
                'constraint' => $this->constraint
            ]
        ];
    }
}