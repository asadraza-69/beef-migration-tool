<?php

namespace Nudelsalat\Migrations\Routing;

use Nudelsalat\Migrations\Migration;

/**
 * Default router that allows all migrations on all databases.
 * This is the simplest possible routing strategy.
 */
class AllowAllMigrationRouter implements MigrationRouterInterface
{
    /** @var string[] */
    private array $databases;

    public function __construct(array $databases = ['default'])
    {
        $this->databases = $databases;
    }

    public function allowMigrate(string $databaseAlias, string $appLabel, ?Migration $migration = null): bool
    {
        return in_array($databaseAlias, $this->databases, true);
    }

    public function dbForModel(string $modelName): string
    {
        return $this->databases[0] ?? 'default';
    }

    public function dbForTable(string $tableName): string
    {
        return $this->databases[0] ?? 'default';
    }

    public function allowRelation(string $fromDb, string $toDb, ?string $appLabel = null): bool
    {
        return $fromDb === $toDb;
    }

    public function getDatabaseAliases(): array
    {
        return $this->databases;
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