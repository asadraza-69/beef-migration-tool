<?php

namespace Nudelsalat\Migrations\Routing;

use Nudelsalat\Migrations\Migration;
use Nudelsalat\Schema\Table;

/**
 * Extended Migration Router Interface providing database routing.
 * 
 * This interface extends the base router to support:
 * - Multi-database routing for models
 * - Cross-database foreign key handling
 * - Database selection for specific tables
 */
interface MigrationRouterInterface
{
    /**
     * Determine if a migration should run on the given database.
     * 
     * @param string $databaseAlias The database alias to check
     * @param string $appLabel The app label of the migration
     * @param Migration|null $migration The migration being run (can provide operation details)
     * @return bool True if the migration should run on this database
     */
    public function allowMigrate(string $databaseAlias, string $appLabel, ?Migration $migration = null): bool;

    /**
     * Determine which database to use for a specific model.
     * 
     * @param string $modelName The model name (can include app prefix)
     * @return string The database alias to use
     */
    public function dbForModel(string $modelName): string;

    /**
     * Determine which database to use for a specific table.
     * 
     * @param string $tableName The table name
     * @return string The database alias to use
     */
    public function dbForTable(string $tableName): string;

    /**
     * Determine if a foreign key relationship is allowed across databases.
     * 
     * @param string $fromDb Database alias for the source
     * @param string $toDb Database alias for the target
     * @param string|null $appLabel The app label
     * @return bool True if the relationship is allowed
     */
    public function allowRelation(string $fromDb, string $toDb, ?string $appLabel = null): bool;

    /**
     * Get all database aliases that should be considered for migrations.
     * 
     * @return string[]
     */
    public function getDatabaseAliases(): array;

    /**
     * Get the alias of the database that data should be routers to when writing.
     * 
     * @param string $modelName The model being written to
     * @return string The database alias
     */
    public function dbForWrite(string $modelName): string;

    /**
     * Get the alias of the database that data should be routers to when reading.
     * 
     * @param string $modelName The model being read from
     * @return string The database alias
     */
    public function dbForRead(string $modelName): string;

    /**
     * Determine if the given database allows multi-table inheritance.
     * 
     * @param string $databaseAlias The database alias
     * @return bool True if the database allows multi-table inheritance
     */
    public function allowsMultiTableInheritance(string $databaseAlias): bool;
}