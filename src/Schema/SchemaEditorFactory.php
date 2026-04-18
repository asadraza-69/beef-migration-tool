<?php

namespace Nudelsalat\Schema;

class SchemaEditorFactory
{
    public static function create(\PDO $pdo, ?string $driver = null): SchemaEditor
    {
        $driver = $driver ?: $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        return match ($driver) {
            'mysql' => new MySQLSchemaEditor($pdo),
            'pgsql', 'postgres' => new PostgreSQLSchemaEditor($pdo),
            'sqlite' => new SQLiteSchemaEditor($pdo),
            default => throw new \Exception("Unsupported database driver: {$driver}"),
        };
    }
}
