<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Migrations\StateRegistry;
use Nudelsalat\Schema\Index;
use Nudelsalat\Schema\SchemaEditor;

class AddIndex extends Operation
{
    public function __construct(
        public string $modelName,
        public Index $index
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if ($table) {
            $table->addIndex($this->index);
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($schemaEditor->tableExists($this->modelName) && !$schemaEditor->indexExists($this->modelName, $this->index->name)) {
            $schemaEditor->addIndex($this->modelName, $this->index);
        }
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($schemaEditor->tableExists($this->modelName) && $schemaEditor->indexExists($this->modelName, $this->index->name)) {
            $schemaEditor->removeIndex($this->modelName, $this->index->name);
        }
    }

    public function describe(): string
    {
        return sprintf("AddIndex(model='%s', index='%s')", $this->modelName, $this->index->name);
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->modelName,
                $this->index,
            ],
        ];
    }
}
