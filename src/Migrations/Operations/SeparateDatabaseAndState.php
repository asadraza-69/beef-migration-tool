<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Migrations\StateRegistry;
use Nudelsalat\Schema\SchemaEditor;

class SeparateDatabaseAndState extends Operation
{
    /**
     * @param Operation[] $databaseOperations
     * @param Operation[] $stateOperations
     */
    public function __construct(
        public array $databaseOperations = [],
        public array $stateOperations = []
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        foreach ($this->stateOperations as $op) {
            $op->stateForwards($state);
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        foreach ($this->databaseOperations as $op) {
            $op->databaseForwards($schemaEditor, $fromState, $toState, $registry);
        }
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Recursively handle backwards if possible
        foreach (array_reverse($this->databaseOperations) as $op) {
            $op->databaseBackwards($schemaEditor, $fromState, $toState, $registry);
        }
    }

    public function describe(): string
    {
        return "SeparateDatabaseAndState (" . count($this->databaseOperations) . " DB ops, " . count($this->stateOperations) . " state ops)";
    }

    public function reversible(): bool
    {
        foreach ($this->databaseOperations as $op) {
            if (method_exists($op, 'reversible') && !$op->reversible()) {
                return false;
            }
        }
        return true;
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->databaseOperations,
                $this->stateOperations
            ]
        ];
    }
}
