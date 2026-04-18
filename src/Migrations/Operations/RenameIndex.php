<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

/**
 * Operation to rename an index.
 *
 * This operation renames an existing index on a model's table.
 */
class RenameIndex extends Operation
{
    /**
     * @param string $modelName The model name (table name)
     * @param string $newName The new index name
     * @param string|null $oldName The old index name (required if renaming by name)
     * @param array|null $oldFields The old field names (alternative to oldName for unnamed indexes)
     */
    public function __construct(
        public string $modelName,
        public string $newName,
        public ?string $oldName = null,
        public ?array $oldFields = null
    ) {}

    /**
     * Mutate the project state to reflect the index rename.
     *
     * @param ProjectState $state The current project state
     */
    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if (!$table) {
            return;
        }

        $indexes = $table->getIndexes();
        $oldIndexName = $this->oldName;

        // If oldName not provided, try to find by oldFields
        if ($oldIndexName === null && !empty($this->oldFields)) {
            foreach ($indexes as $idxName => $index) {
                if ($index->columns === $this->oldFields) {
                    $oldIndexName = $idxName;
                    break;
                }
            }
        }

        if ($oldIndexName && isset($indexes[$oldIndexName])) {
            $index = $indexes[$oldIndexName];
            unset($indexes[$oldIndexName]);
            $index->name = $this->newName;
            $indexes[$this->newName] = $index;

            // Rebuild the table's indexes
            $table->options['indexes'] = array_values($indexes);
        }
    }

    /**
     * Apply the index rename to the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation
     * @param ProjectState $toState The state after this operation
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $oldIndexName = $this->oldName;

        // If oldName not provided, try to find by oldFields
        if ($oldIndexName === null && !empty($this->oldFields)) {
            $table = $fromState->getTable($this->modelName);
            if ($table) {
                foreach ($table->getIndexes() as $idxName => $index) {
                    if ($index->columns === $this->oldFields) {
                        $oldIndexName = $idxName;
                        break;
                    }
                }
            }
        }

        if ($oldIndexName) {
            $schemaEditor->renameIndex($this->modelName, $oldIndexName, $this->newName);
        }
    }

    /**
     * Reverse the index rename in the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation (forward state)
     * @param ProjectState $toState The state after this operation (backward state)
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Swap the direction - use newName as old and oldName as new
        $oldName = $this->oldName;
        // For reversal, we need to find the current index (which was renamed to newName)
        // and rename it back to oldName
        if ($oldName) {
            $schemaEditor->renameIndex($this->modelName, $this->newName, $oldName);
        }
    }

    /**
     * Return a human-readable description of this operation.
     *
     * @return string The operation description
     */
    public function describe(): string
    {
        if ($this->oldName) {
            return "Rename index {$this->oldName} on {$this->modelName} to {$this->newName}";
        }
        return "Rename unnamed index for " . implode(', ', $this->oldFields ?? []) . " on {$this->modelName} to {$this->newName}";
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
                'newName' => $this->newName,
                'oldName' => $this->oldName,
                'oldFields' => $this->oldFields
            ]
        ];
    }
}