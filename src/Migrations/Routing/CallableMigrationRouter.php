<?php

namespace Nudelsalat\Migrations\Routing;

use Nudelsalat\Migrations\Migration;

/**
 * Callable-based migration router that allows custom routing logic.
 * 
 * This router wraps callable functions for routing decisions.
 */
class CallableMigrationRouter implements MigrationRouterInterface
{
    /** @var callable */
    private $router;

    public function __construct(callable $router)
    {
        $this->router = $router;
    }

    public function allowMigrate(string $databaseAlias, string $appLabel, ?Migration $migration = null): bool
    {
        return ($this->router)($databaseAlias, $appLabel, $migration);
    }

    public function dbForModel(string $modelName): string
    {
        return 'default';
    }

    public function dbForTable(string $tableName): string
    {
        return 'default';
    }

    public function allowRelation(string $fromDb, string $toDb, ?string $appLabel = null): bool
    {
        return $fromDb === $toDb;
    }

    public function getDatabaseAliases(): array
    {
        return ['default'];
    }

    public function dbForWrite(string $modelName): string
    {
        return $this->dbForModel($modelName);
    }

    public function dbForRead(string $modelName): string
    {
        return $this->dbForModel($modelName);
    }

    public function allowsMultiTableInheritance(string $databaseAlias): bool
    {
        return true;
    }
}