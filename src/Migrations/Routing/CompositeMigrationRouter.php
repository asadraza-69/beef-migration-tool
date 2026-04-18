<?php

namespace Nudelsalat\Migrations\Routing;

use Nudelsalat\Migrations\Migration;

/**
 * Composite router that checks multiple routers in sequence.
 * 
 * First matching router wins.
 */
class CompositeMigrationRouter implements MigrationRouterInterface
{
    /** @var MigrationRouterInterface[] */
    private array $routers;

    public function __construct(MigrationRouterInterface ...$routers)
    {
        $this->routers = $routers;
    }

    public function allowMigrate(string $databaseAlias, string $appLabel, ?Migration $migration = null): bool
    {
        foreach ($this->routers as $router) {
            if (!$router->allowMigrate($databaseAlias, $appLabel, $migration)) {
                return false;
            }
        }
        return true;
    }

    public function dbForModel(string $modelName): string
    {
        foreach ($this->routers as $router) {
            $db = $router->dbForModel($modelName);
            if ($db !== 'default' && $db !== '') {
                return $db;
            }
        }
        return 'default';
    }

    public function dbForTable(string $tableName): string
    {
        foreach ($this->routers as $router) {
            $db = $router->dbForTable($tableName);
            if ($db !== 'default' && $db !== '') {
                return $db;
            }
        }
        return 'default';
    }

    public function allowRelation(string $fromDb, string $toDb, ?string $appLabel = null): bool
    {
        foreach ($this->routers as $router) {
            if (!$router->allowRelation($fromDb, $toDb, $appLabel)) {
                return false;
            }
        }
        return true;
    }

    public function getDatabaseAliases(): array
    {
        $aliases = [];
        foreach ($this->routers as $router) {
            $aliases = array_merge($aliases, $router->getDatabaseAliases());
        }
        return array_unique($aliases);
    }

    public function dbForWrite(string $modelName): string
    {
        foreach ($this->routers as $router) {
            if (method_exists($router, 'dbForWrite')) {
                $db = $router->dbForWrite($modelName);
                if ($db !== 'default' && $db !== '') {
                    return $db;
                }
            }
        }
        return $this->dbForModel($modelName);
    }

    public function dbForRead(string $modelName): string
    {
        foreach ($this->routers as $router) {
            if (method_exists($router, 'dbForRead')) {
                $db = $router->dbForRead($modelName);
                if ($db !== 'default' && $db !== '') {
                    return $db;
                }
            }
        }
        return $this->dbForModel($modelName);
    }

    public function allowsMultiTableInheritance(string $databaseAlias): bool
    {
        foreach ($this->routers as $router) {
            if (method_exists($router, 'allowsMultiTableInheritance')) {
                if (!$router->allowsMultiTableInheritance($databaseAlias)) {
                    return false;
                }
            }
        }
        return true;
    }
}