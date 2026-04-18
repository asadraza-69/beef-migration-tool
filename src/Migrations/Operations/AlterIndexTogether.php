<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

/**
 * Operation to change the index_together option on a model.
 *
 * This operation sets the index_together option on a model, specifying
 * combinations of fields that should be indexed together (but without
 * unique constraints).
 */
class AlterIndexTogether extends Operation
{
    /**
     * @param string $name The model name to alter
     * @param array $indexTogether Array of field name arrays that define indexes,
     *                              e.g., [['field1', 'field2'], ['field3', 'field4']]
     */
    public function __construct(
        public string $name,
        public array $indexTogether = []
    ) {}

    /**
     * Mutate the project state to reflect the index_together change.
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
            foreach ($this->indexTogether as $index) {
                if (is_array($index)) {
                    $normalized[] = $index;
                } else {
                    $normalized[] = [$index];
                }
            }
            $options['index_together'] = $normalized;
            $table->options = $options;
        }
    }

    /**
     * Apply the index_together change to the database.
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

        $oldIndex = $oldTable->options['index_together'] ?? [];
        $newIndex = $newTable->options['index_together'] ?? [];

        // Normalize both to comparable format
        $oldSet = array_map('serialize', $oldIndex);
        $newSet = array_map('serialize', $newIndex);

        // Remove indexes that are no longer in new
        foreach ($oldIndex as $oldIdx) {
            if (!in_array(serialize($oldIdx), $newSet)) {
                $schemaEditor->alterIndexTogether(
                    $this->name,
                    $oldIdx,
                    []
                );
            }
        }

        // Add indexes that are new
        foreach ($newIndex as $newIdx) {
            if (!in_array(serialize($newIdx), $oldSet)) {
                $schemaEditor->alterIndexTogether(
                    $this->name,
                    [],
                    $newIdx
                );
            }
        }
    }

    /**
     * Reverse the index_together change in the database.
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
        $count = count($this->indexTogether);
        return "Alter index_together for {$this->name} ({$count} index(es))";
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
                'indexTogether' => $this->indexTogether
            ]
        ];
    }
}