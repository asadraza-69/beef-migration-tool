<?php

namespace Nudelsalat\Schema;

class Table
{
    /** @var Column[] */
    private array $columns = [];

    /** @var Index[] */
    private array $indexes = [];

    /** @var Constraint[] */
    private array $constraints = [];

    /** @var ForeignKeyConstraint[] */
    private array $foreignKeys = [];

    private ?string $primaryKeyColumn = null;
    private ?string $primaryKeyName = null;

    public function __construct(
        public string $name,
        array $columns = [],
        public array $options = [],
        array $indexes = [],
        array $constraints = [],
        array $foreignKeys = []
    ) {
        foreach ($columns as $column) {
            $this->addColumn($column);
        }
        foreach ($indexes as $index) {
            $this->addIndex($index);
        }
        foreach ($constraints as $constraint) {
            $this->addConstraint($constraint);
        }
        foreach ($foreignKeys as $fk) {
            $this->addForeignKey($fk);
        }
    }

    public function addColumn(Column $column): void
    {
        $this->columns[$column->name] = $column;
    }

    public function removeColumn(string $name): void
    {
        unset($this->columns[$name]);
    }

    public function getColumn(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    public function setColumnPrimaryKey(string $name): void
    {
        $this->primaryKeyColumn = $name;
    }

    public function getPrimaryKeyColumn(): ?string
    {
        return $this->primaryKeyColumn;
    }

    public function setPrimaryKeyName(?string $name): void
    {
        $this->primaryKeyName = $name;
    }

    public function getPrimaryKeyName(): ?string
    {
        return $this->primaryKeyName;
    }

    /** @return Column[] */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function addIndex(Index $index): void
    {
        $this->indexes[$index->name] = $index;
    }

    public function removeIndex(string $name): void
    {
        unset($this->indexes[$name]);
    }

    /** @return Index[] */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    public function addConstraint(Constraint $constraint): void
    {
        $this->constraints[$constraint->name] = $constraint;
    }

    public function removeConstraint(string $name): void
    {
        unset($this->constraints[$name]);
    }

    /** @return Constraint[] */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    public function addForeignKey(ForeignKeyConstraint $fk): void
    {
        $this->foreignKeys[$fk->name] = $fk;
    }

    public function removeForeignKey(string $name): void
    {
        unset($this->foreignKeys[$name]);
    }

    /** @return ForeignKeyConstraint[] */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => array_map(fn(Column $c) => $c->toArray(), $this->columns),
            'indexes' => array_map(fn(Index $i) => $i->toArray(), $this->indexes),
            'constraints' => array_map(fn(Constraint $c) => $c->toArray(), $this->constraints),
            'foreignKeys' => array_map(fn(ForeignKeyConstraint $fk) => $fk->toArray(), $this->foreignKeys),
            'options' => $this->options,
            'primaryKeyName' => $this->primaryKeyName,
        ];
    }

    public static function fromArray(array $data): self
    {
        $columns = array_map(fn(array $c) => Column::fromArray($c), $data['columns']);
        $indexes = array_map(fn(array $i) => Index::fromArray($i), $data['indexes'] ?? []);
        $constraints = array_map(fn(array $c) => Constraint::fromArray($c), $data['constraints'] ?? []);
        $fks = array_map(fn(array $fk) => ForeignKeyConstraint::fromArray($fk), $data['foreignKeys'] ?? []);
        
        $table = new self($data['name'], $columns, $data['options'] ?? [], $indexes, $constraints, $fks);
        if (isset($data['primaryKeyName'])) {
            $table->setPrimaryKeyName($data['primaryKeyName']);
        }
        return $table;
    }
}
