<?php

namespace Nudelsalat\Schema;

class FunctionDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $definition,
        public readonly string $identityArguments,
        public readonly string $schema
    ) {}

    public function getDropSql(): string
    {
        $args = $this->identityArguments !== '' ? "({$this->identityArguments})" : "()";
        return "DROP FUNCTION IF EXISTS {$this->schema}.{$this->name}{$args} CASCADE";
    }
}