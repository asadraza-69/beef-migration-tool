<?php

namespace Nudelsalat\Migrations;

use Nudelsalat\Migrations\Operations\Operation;
use Nudelsalat\Schema\SchemaEditor;

abstract class Migration
{
    /** @var string */
    public string $name;

    /** @var string */
    public string $path;

    /** @var bool */
    public bool $atomic = true;

    /** @var bool */
    public bool $initial = false;
    
    /** @var Operation[] */
    public array $operations = [];

    /** @var array Array of [migrationName] strings */
    public array $dependencies = [];

    /** @var array Array of [migrationName] strings replaced by this migration */
    public array $replaces = [];

    /** @var array Array of [migrationName] strings that should run after this migration */
    public array $runBefore = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Mutate the state forwards without touching the database.
     */
    public function mutateState(ProjectState $state): ProjectState
    {
        foreach ($this->operations as $operation) {
            $operation->stateForwards($state);
        }
        return $state;
    }

    /**
     * Apply the migration to the database.
     */
    public function apply(ProjectState $state, SchemaEditor $schemaEditor, StateRegistry $registry): ProjectState
    {
        foreach ($this->operations as $operation) {
            $oldState = $state->clone();
            $operation->stateForwards($state);
            $operation->databaseForwards($schemaEditor, $oldState, $state, $registry);
        }
        return $state;
    }

    /**
     * Unapply the migration from the database.
     */
    public function unapply(ProjectState $state, SchemaEditor $schemaEditor, StateRegistry $registry): ProjectState
    {
        // To unapply, we need the state *before* this migration.
        // We'll run operations in reverse, but the operation needs the correct states.
        
        $toRun = [];
        $tempState = $state->clone();
        
        // Phase 1: Collect states
        foreach ($this->operations as $operation) {
            $oldState = $tempState->clone();
            $operation->stateForwards($tempState);
            $toRun[] = [$operation, $oldState, $tempState];
        }
        
        // Phase 2: Run backwards
        foreach (array_reverse($toRun) as [$operation, $oldState, $newState]) {
            $operation->databaseBackwards($schemaEditor, $oldState, $newState, $registry);
        }
        
        return $state; // The caller should handle resetting the actual ProjectState correctly
    }
}
