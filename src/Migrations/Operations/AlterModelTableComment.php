<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

/**
 * Operation to change the comment on a model's database table.
 *
 * This operation sets or changes the database table comment for a model.
 */
class AlterModelTableComment extends Operation
{
    /**
     * @param string $name The model name (table name) to alter
     * @param string|null $tableComment The new table comment, or null to remove
     */
    public function __construct(
        public string $name,
        public ?string $tableComment = null
    ) {}

    /**
     * Mutate the project state to reflect the table comment change.
     *
     * @param ProjectState $state The current project state
     */
    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->name);
        if ($table) {
            $options = $table->options;
            if ($this->tableComment === null) {
                unset($options['db_table_comment']);
            } else {
                $options['db_table_comment'] = $this->tableComment;
            }
            $table->options = $options;
        }
    }

    /**
     * Apply the table comment change to the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation
     * @param ProjectState $toState The state after this operation
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $table = $toState->getTable($this->name);
        if ($table && $schemaEditor->supportsComments()) {
            $tableName = $table->options['db_table'] ?? $this->name;
            $comment = $table->options['db_table_comment'] ?? null;
            $schemaEditor->setTableComment($tableName, $comment);
        }
    }

    /**
     * Reverse the table comment change in the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation (forward state)
     * @param ProjectState $toState The state after this operation (backward state)
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Table comment changes are reversible
        $this->databaseForwards($schemaEditor, $toState, $fromState, $registry);
    }

    /**
     * Return a human-readable description of this operation.
     *
     * @return string The operation description
     */
    public function describe(): string
    {
        if ($this->tableComment === null) {
            return "Remove table comment for {$this->name}";
        }
        return "Set table comment for {$this->name}";
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
                'tableComment' => $this->tableComment
            ]
        ];
    }
}