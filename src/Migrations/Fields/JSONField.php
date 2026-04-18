<?php

namespace Nudelsalat\Migrations\Fields;

class JSONField extends Field
{
    public function getType(): string
    {
        return 'json';
    }

    public function getSqlType(string $driver): string
    {
        return match ($driver) {
            'pgsql' => 'JSONB',
            'mysql' => 'JSON',
            'sqlite' => 'TEXT', // SQLite uses TEXT for JSON
            default => 'TEXT',
        };
    }

    /**
     * Data conversion for RunPHP / Historical Models
     */
    public function toPhp(mixed $value): mixed
    {
        if (is_string($value)) {
            return json_decode($value, true);
        }
        return $value;
    }

    public function toDatabase(mixed $value): string
    {
        return json_encode($value);
    }
}
