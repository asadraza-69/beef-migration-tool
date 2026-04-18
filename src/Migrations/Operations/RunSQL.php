<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

class RunSQL extends Operation
{
    public function __construct(
        public string $sql,
        public ?string $reverseSql = null
    ) {}

    public function stateForwards(ProjectState $state): void
    {
        // Custom SQL usually doesn't affect automated schema state tracking
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        $schemaEditor->runRawSql($this->sql);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($this->reverseSql) {
            $schemaEditor->runRawSql($this->reverseSql);
        }
    }

    public function describe(): string
    {
        return "Run raw SQL: " . substr($this->sql, 0, 50) . "...";
    }

    public function reversible(): bool
    {
        return $this->reverseSql !== null;
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->sql,
                $this->reverseSql
            ]
        ];
    }
}
