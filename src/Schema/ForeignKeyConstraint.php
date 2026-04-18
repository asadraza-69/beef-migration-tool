<?php

namespace Nudelsalat\Schema;

class ForeignKeyConstraint
{
    public function __construct(
        public string $name,
        public string $column,
        public string $toTable,
        public string $toColumn,
        public string $onDelete = 'CASCADE'
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'column' => $this->column,
            'toTable' => $this->toTable,
            'toColumn' => $this->toColumn,
            'onDelete' => $this->onDelete,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['column'],
            $data['toTable'],
            $data['toColumn'],
            $data['onDelete'] ?? 'CASCADE'
        );
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->name,
                $this->column,
                $this->toTable,
                $this->toColumn,
                $this->onDelete,
            ],
        ];
    }
}
