<?php

namespace Nudelsalat\Schema;

class TriggerDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $definition,
        public readonly string $tableName,
        public readonly string $schema
    ) {}

    public function getDropSql(): string
    {
        return "DROP TRIGGER IF EXISTS {$this->name} ON {$this->schema}.{$this->tableName} CASCADE";
    }
}