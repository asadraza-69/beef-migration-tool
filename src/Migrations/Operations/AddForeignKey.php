<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Schema\ForeignKeyConstraint;
use Nudelsalat\Migrations\StateRegistry;

class AddForeignKey extends Operation
{
    public function __construct(
        public string $modelName,
        public string $name,
        public string $column,
        public string $toModel,
        public string $toColumn,
        public string $onDelete = 'CASCADE'
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if ($table) {
            $table->addForeignKey(new ForeignKeyConstraint(
                $this->name,
                $this->column,
                $this->toModel,
                $this->toColumn,
                $this->onDelete
            ));
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $schemaEditor->addForeignKey($this->modelName, $this->column, $this->toModel, $this->toColumn, $this->onDelete);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $schemaEditor->removeForeignKey($this->modelName, $this->name);
    }

    public function describe(): string
    {
        return "Add foreign key {$this->name} on {$this->modelName} to {$this->toModel}.{$this->toColumn}";
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->modelName,
                $this->name,
                $this->column,
                $this->toModel,
                $this->toColumn,
                $this->onDelete
            ]
        ];
    }
}
