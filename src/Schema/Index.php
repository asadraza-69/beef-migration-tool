<?php

namespace Nudelsalat\Schema;

class Index
{
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'unique' => $this->unique,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['columns'], $data['unique']);
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->name,
                $this->columns,
                $this->unique,
            ],
        ];
    }
}
