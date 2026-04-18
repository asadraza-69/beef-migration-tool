<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Migrations\StateRegistry;
use Nudelsalat\Schema\SchemaEditor;

class RemoveIndex extends Operation
{
    public function __construct(
        public string $modelName,
        public string $name
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if ($table) {
            $table->removeIndex($this->name);
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $schemaEditor->removeIndex($this->modelName, $this->name);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $oldTable = $fromState->getTable($this->modelName);
        $index = $oldTable->getIndexes()[$this->name];
        $schemaEditor->addIndex($this->modelName, $index);
    }

    public function describe(): string
    {
        return sprintf("RemoveIndex(model='%s', index='%s')", $this->modelName, $this->name);
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->modelName,
                $this->name,
            ],
        ];
    }
}
