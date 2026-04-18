<?php

namespace Nudelsalat\Schema;

class ViewDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $definition,
        public readonly bool $isMaterialized,
        public readonly string $schema
    ) {}

    public function getDropSql(): string
    {
        $type = $this->isMaterialized ? 'MATERIALIZED VIEW' : 'VIEW';
        return "DROP {$type} IF EXISTS {$this->schema}.{$this->name} CASCADE";
    }
}