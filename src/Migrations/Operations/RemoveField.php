<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

class RemoveField extends Operation
{
    public function __construct(
        public string $modelName,
        public string $name
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if ($table) {
            $table->removeColumn($this->name);
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $schemaEditor->removeColumn($this->modelName, $this->name);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $oldTable = $fromState->getTable($this->modelName);
        $column = $oldTable->getColumn($this->name);
        $schemaEditor->addColumn($this->modelName, $column);
    }

    public function describe(): string
    {
        return "Remove field {$this->name} from {$this->modelName}";
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
