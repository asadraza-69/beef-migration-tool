<?php

namespace Nudelsalat\Schema;

class Column
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = false,
        public mixed $default = null,
        public bool $primaryKey = false,
        public bool $autoIncrement = false,
        public array $options = [],
        public ?string $sequenceName = null
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'primaryKey' => $this->primaryKey,
            'autoIncrement' => $this->autoIncrement,
            'options' => $this->options,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['type'],
            $data['nullable'] ?? false,
            $data['default'] ?? null,
            $data['primaryKey'] ?? false,
            $data['autoIncrement'] ?? false,
            $data['options'] ?? []
        );
    }

    public function deconstruct(): array
    {
        return [
            get_class($this),
            [
                $this->name,
                $this->type,
                $this->nullable,
                $this->default,
                $this->primaryKey,
                $this->autoIncrement
            ]
        ];
    }
}
