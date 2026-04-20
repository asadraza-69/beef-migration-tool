<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Migrations\StateRegistry;
use Nudelsalat\Schema\Constraint;
use Nudelsalat\Schema\SchemaEditor;

class AddConstraint extends Operation
{
    public function __construct(
        public string $modelName,
        public Constraint $constraint
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        $table = $state->getTable($this->modelName);
        if ($table) {
            $table->addConstraint($this->constraint);
        }
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($schemaEditor->tableExists($this->modelName)) {
            $schemaEditor->addConstraint($this->modelName, $this->constraint);
        }
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($schemaEditor->tableExists($this->modelName)) {
            $schemaEditor->removeConstraint($this->modelName, $this->constraint->name);
        }
    }

    public function describe(): string
    {
        return sprintf("AddConstraint(model='%s', constraint='%s')", $this->modelName, $this->constraint->name);
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->modelName,
                $this->constraint,
            ],
        ];
    }
}
