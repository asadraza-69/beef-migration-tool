<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

class RemoveForeignKey extends Operation
{
    public function __construct(
        public string $modelName,
        public string $name
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if ($table) {
            $table->removeForeignKey($this->name);
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $schemaEditor->removeForeignKey($this->modelName, $this->name);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        // Reversing a removal would require full FK details which we don't always have here.
        // For simple parity, we'll assume forward-only or manual rollback if complex.
    }

    public function describe(): string
    {
        return "Remove foreign key {$this->name} from {$this->modelName}";
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->modelName,
                $this->name
            ]
        ];
    }
}
