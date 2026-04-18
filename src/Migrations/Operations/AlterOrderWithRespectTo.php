<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

/**
 * Operation to change the order_with_respect_to option on a model.
 *
 * This option makes objects orderable with respect to a given foreign key field.
 * It adds an implicit _order field to the model.
 */
class AlterOrderWithRespectTo extends Operation
{
    /**
     * @param string $name The model name to alter
     * @param string|null $orderWithRespectTo The field name to order by, or null to remove
     */
    public function __construct(
        public string $name,
        public ?string $orderWithRespectTo = null
    ) {}

    /**
     * Mutate the project state to reflect the order_with_respect_to change.
     *
     * @param ProjectState $state The current project state
     */
    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->name);
        if ($table) {
            $options = $table->options;
            if ($this->orderWithRespectTo === null) {
                unset($options['order_with_respect_to']);
            } else {
                $options['order_with_respect_to'] = $this->orderWithRespectTo;
            }
            $table->options = $options;
        }
    }

    /**
     * Apply the order_with_respect_to change to the database.
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

        $oldOrder = $oldTable->options['order_with_respect_to'] ?? null;
        $newOrder = $newTable->options['order_with_respect_to'] ?? null;

        // If going from order_with_respect_to to nothing, remove the _order column
        if ($oldOrder && !$newOrder) {
            $schemaEditor->removeField($this->name, '_order');
        }
        // If going from nothing to order_with_respect_to, add the _order column
        elseif (!$oldOrder && $newOrder) {
            // Add the _order field - this would need the column definition
            // For now, we'll add a basic integer field
            $schemaEditor->addField(
                $this->name,
                'Nudelsalat\Migrations\Fields\IntegerField',
                ['name' => '_order', 'default' => 0, 'nullable' => true]
            );
        }
        // If changing the field, we need to rename
        elseif ($oldOrder && $newOrder && $oldOrder !== $newOrder) {
            // This is complex - the _order field name is always _order
            // So we just need to update the reference
        }
    }

    /**
     * Reverse the order_with_respect_to change in the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation (forward state)
     * @param ProjectState $toState The state after this operation (backward state)
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Swap the direction
        $this->databaseForwards($schemaEditor, $toState, $fromState, $registry);
    }

    /**
     * Return a human-readable description of this operation.
     *
     * @return string The operation description
     */
    public function describe(): string
    {
        if ($this->orderWithRespectTo === null) {
            return "Remove order_with_respect_to from {$this->name}";
        }
        return "Set order_with_respect_to on {$this->name} to {$this->orderWithRespectTo}";
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
                'orderWithRespectTo' => $this->orderWithRespectTo
            ]
        ];
    }
}