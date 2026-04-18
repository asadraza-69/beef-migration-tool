<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Migrations\Executor;
use Nudelsalat\Schema\SchemaEditorFactory;

class SqlMigrateCommand extends BaseCommand
{
    public function execute(array $args): void
    {
        $databaseAlias = $this->getOptionValue($args, '--database') ?: $this->bootstrap->getConfig()->getDefaultDatabaseAlias();
        $positionals = $this->getPositionalArgs($args);

        if (count($positionals) < 1) {
            $this->error("Usage: nudelsalat sqlmigrate <migration_name> [driver]");
            return;
        }

        $migrationName = $positionals[0];
        $config = $this->bootstrap->getConfig();
        $pdo = new \PDO("sqlite::memory:"); // Temporary PDO for hijacking
        
        $dbConfig = $config->getDatabaseConfig($databaseAlias);
        $driver = $positionals[1] ?? $dbConfig['driver'] ?? 'mysql';
        
        $loader = $this->bootstrap->createLoader();
        $graph = $loader->getGraph();
        
        // Partial name match
        $resolvedName = null;
        if (isset($graph->nodes[$migrationName])) {
            $resolvedName = $migrationName;
        } else {
            foreach ($graph->nodes as $name => $migration) {
                if (str_contains($name, $migrationName)) {
                    $resolvedName = $name;
                    break;
                }
            }
        }
        
        if ($resolvedName === null) {
            $this->error("Migration '{$migrationName}' not found.");
            return;
        }

        $migration = $graph->nodes[$resolvedName];
        $schemaEditor = SchemaEditorFactory::create($pdo, $driver);
        $schemaEditor->enableDryRun(static function (string $sql): void {
            echo $sql . ";\n";
        });

        // We need a dummy executor just to get the state logic
        $recorder = $this->bootstrap->createRecorder($databaseAlias);
        $executor = new Executor($pdo, $loader, $recorder, $schemaEditor, $this->bootstrap->getDispatcher(), $databaseAlias, $this->bootstrap->getMigrationRouter());
        
        $state = $executor->projectStateAtApplied($resolvedName);
        $registry = new \Nudelsalat\Migrations\StateRegistry($state, $pdo);
        
        $this->info("-- SQL for migration: {$resolvedName} (Driver: {$driver})");
        $migration->apply($state, $schemaEditor, $registry);
        $schemaEditor->flushDeferredSql();
    }
}
