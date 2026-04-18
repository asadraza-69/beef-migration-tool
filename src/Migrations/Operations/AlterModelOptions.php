<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

/**
 * Operation to change model Meta options that don't affect the database schema.
 *
 * This operation handles changes to model options like verbose_name, verbose_name_plural,
 * permissions, default_permissions, ordering, etc. These options don't directly affect
 * the database structure but are used by the ORM and admin.
 */
class AlterModelOptions extends Operation
{
    /**
     * Model options that can be altered and should be preserved in the operation.
     * These are Model Meta options that don't affect database schema.
     */
    public const ALTER_OPTION_KEYS = [
        'base_manager_name',
        'default_manager_name',
        'default_related_name',
        'get_latest_by',
        'managed',
        'ordering',
        'permissions',
        'default_permissions',
        'select_on_save',
        'verbose_name',
        'verbose_name_plural',
    ];

    /**
     * @param string $name The model name to alter
     * @param array $options The new options to set (key-value pairs)
     */
    public function __construct(
        public string $name,
        public array $options = []
    ) {}

    /**
     * Mutate the project state to reflect the options change.
     *
     * @param ProjectState $state The current project state
     */
    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->name);
        if ($table) {
            $table->options = array_merge($table->options, $this->options);
        }
    }

    /**
     * Apply the options change to the database.
     *
     * AlterModelOptions doesn't directly affect the database schema - the state
     * change is what's important for the ORM. This is a no-op at the database level.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation
     * @param ProjectState $toState The state after this operation
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // This operation only affects the in-memory state, not the database
        // No database changes needed
    }

    /**
     * Reverse the options change in the database.
     *
     * @param SchemaEditor $schemaEditor The schema editor for the current database
     * @param ProjectState $fromState The state before this operation (forward state)
     * @param ProjectState $toState The state after this operation (backward state)
     * @param StateRegistry $registry The state registry for model lookups
     */
    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // This operation only affects the in-memory state
        // No database changes needed - just apply the reverse state transformation
    }

    /**
     * Return a human-readable description of this operation.
     *
     * @return string The operation description
     */
    public function describe(): string
    {
        $optionKeys = array_keys($this->options);
        return sprintf(
            "Change Meta options on %s: %s",
            $this->name,
            implode(', ', $optionKeys)
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
                'options' => $this->options
            ]
        ];
    }
}