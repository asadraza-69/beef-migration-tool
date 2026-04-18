<?php

namespace Nudelsalat\Migrations;

use Nudelsalat\Schema\Table;

class ProjectState
{
    /** @var Table[] */
    private array $tables = [];

    public function __construct(array $tables = [])
    {
        foreach ($tables as $table) {
            $this->addTable($table);
        }
    }

    public function addTable(Table $table): void
    {
        $this->tables[$table->name] = $table;
    }

    public function removeTable(string $name): void
    {
        unset($this->tables[$name]);
    }

    public function getTable(string $name): ?Table
    {
        return $this->tables[$name] ?? null;
    }

    /** @return Table[] */
    public function getTables(): array
    {
        return $this->tables;
    }

    public function clone(): self
    {
        $newTables = [];
        foreach ($this->tables as $table) {
            $newTables[] = Table::fromArray($table->toArray());
        }
        return new self($newTables);
    }
}
