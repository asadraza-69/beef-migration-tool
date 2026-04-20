<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\Constraint;
use Nudelsalat\Schema\Index;
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
        $indexes = [];
        foreach (($this->options['indexes'] ?? []) as $index) {
            if ($index instanceof Index) {
                $indexes[] = $index;
                continue;
            }
            if (is_array($index)) {
                $indexes[] = Index::fromArray($index);
            }
        }

        $constraints = [];
        foreach (($this->options['constraints'] ?? []) as $constraint) {
            if ($constraint instanceof Constraint) {
                $constraints[] = $constraint;
                continue;
            }
            if (is_array($constraint)) {
                $constraints[] = Constraint::fromArray($constraint);
            }
        }

        $table = new Table($this->name, $this->fields, $this->options, $indexes, $constraints);
        $state->addTable($table);
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $table = $toState->getTable($this->name);
        $dbName = $table?->options['db_table'] ?? $this->name;
        $schemaEditor->createTable($table, $dbName);

        // Support CreateModel ops that embed indexes/constraints in options (optimizer output).
        foreach ($table?->getConstraints() ?? [] as $constraint) {
            $schemaEditor->addConstraint($dbName, $constraint);
        }

        foreach ($table?->getIndexes() ?? [] as $index) {
            if (!$schemaEditor->indexExists($dbName, $index->name)) {
                $schemaEditor->addIndex($dbName, $index);
            }
        }
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $table = $toState->getTable($this->name);
        $dbName = $table?->options['db_table'] ?? $this->name;
        if ($schemaEditor->tableExists($dbName)) {
            $schemaEditor->deleteTable($dbName);
        }
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
