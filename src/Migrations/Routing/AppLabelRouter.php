<?php

namespace Nudelsalat\Migrations\Routing;

use Nudelsalat\Migrations\Migration;

/**
 * Router that selects database based on app label.
 * 
 * This allows different apps to use different databases.
 */
class AppLabelRouter implements MigrationRouterInterface
{
    /**
     * @param array<string, string> $appDbMap Map of app label to database alias
     * @param string $defaultDb Default database for unmapped apps
     */
    public function __construct(
        private array $appDbMap = [],
        private string $defaultDb = 'default'
    ) {}

    public function allowMigrate(string $databaseAlias, string $appLabel, ?Migration $migration = null): bool
    {
        $targetDb = $this->appDbMap[$appLabel] ?? $this->defaultDb;
        return $databaseAlias === $targetDb;
    }

    public function dbForModel(string $modelName): string
    {
        if (str_contains($modelName, '.')) {
            [$appLabel, $model] = explode('.', $modelName, 2);
            return $this->appDbMap[$appLabel] ?? $this->defaultDb;
        }
        return $this->defaultDb;
    }

    public function dbForTable(string $tableName): string
    {
        return $this->defaultDb;
    }

    public function allowRelation(string $fromDb, string $toDb, ?string $appLabel = null): bool
    {
        return $fromDb === $toDb;
    }

    public function getDatabaseAliases(): array
    {
        return array_unique(array_values($this->appDbMap + [$this->defaultDb]));
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