<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

abstract class Operation
{
    /**
     * Mutate the in-memory state.
     */
    abstract public function stateForwards(ProjectState $state): void;

    /**
     * Apply the change to the database.
     */
    abstract public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void;

    /**
     * Reverse the change on the database.
     */
    abstract public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void;

    /**
     * Returns the constructor arguments to recreate this operation.
     * @return array [className, [args]]
     */
    abstract public function deconstruct(): array;

    public function describe(): string
    {
        return get_class($this);
    }

    /**
     * Returns whether this operation can be reduced with `$other`.
     *
     * - Return an array (possibly empty) of Operations to replace both this and $other.
     * - Return true  if $other can "pass through" this operation (no relationship).
     * - Return false if $other is a hard optimization boundary (cannot merge over it).
     *
     * Default: return true (unknown pairs don't block optimisation).
     */
    public function reduce(Operation $other, string $appLabel): array|bool
    {
        return true;
    }

    /**
     * Returns whether this operation can be reversed.
     * 
     * Most operations are reversible, but some like RunSQL without reverse_sql
     * cannot be reversed.
     *
     * @return bool True if the operation is reversible
     */
    public function reversible(): bool
    {
        return true;
    }

    /**
     * Returns whether this operation is elidable.
     * 
     * Elidable operations can be removed when squashing migrations because
     * their effects are already captured by other operations.
     *
     * @return bool True if the operation can be elided
     */
    public function elidable(): bool
    {
        return false;
    }
}
