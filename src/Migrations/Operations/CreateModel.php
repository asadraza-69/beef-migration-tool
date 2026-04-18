<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Schema\Table;
use Nudelsalat\Migrations\StateRegistry;

class CreateModel extends Operation
{
    public function __construct(
        public string $name,
        public array $fields = [],
        public array $options = []
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = new Table($this->name, $this->fields, $this->options);
        $state->addTable($table);
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $schemaEditor->createTable($toState->getTable($this->name));
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $schemaEditor->deleteTable($this->name);
    }

    public function describe(): string
    {
        return "Create model {$this->name}";
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->name,
                $this->fields,
                $this->options
            ]
        ];
    }
}
