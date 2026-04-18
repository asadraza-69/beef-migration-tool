<?php

namespace Nudelsalat\Schema;

class Constraint
{
    public function __construct(
        public string $name,
        public string $type, // 'unique', 'check'
        public array $columns = [],
        public ?string $checkSql = null
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'columns' => $this->columns,
            'checkSql' => $this->checkSql,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['type'], $data['columns'], $data['checkSql']);
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->name,
                $this->type,
                $this->columns,
                $this->checkSql,
            ],
        ];
    }
}
