<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Migrations\StateRegistry;
use Nudelsalat\Schema\SchemaEditor;

class RemoveConstraint extends Operation
{
    public function __construct(
        public string $modelName,
        public string $name
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if ($table) {
            $table->removeConstraint($this->name);
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($schemaEditor->tableExists($this->modelName)) {
            $schemaEditor->removeConstraint($this->modelName, $this->name);
        }
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($schemaEditor->tableExists($this->modelName)) {
            $oldTable = $fromState->getTable($this->modelName);
            $constraint = $oldTable->getConstraints()[$this->name] ?? null;
            if ($constraint) {
                $schemaEditor->addConstraint($this->modelName, $constraint);
            }
        }
    }

    public function describe(): string
    {
        return sprintf("RemoveConstraint(model='%s', constraint='%s')", $this->modelName, $this->name);
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
