<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Migrations\Inspector;
use Nudelsalat\Migrations\Questioner;
use Nudelsalat\Migrations\Autodetector;
use Nudelsalat\Migrations\MigrationWriter;
use Nudelsalat\Migrations\Validator;
use Nudelsalat\Schema\Introspector;

class MakeCommand extends BaseCommand
{
    public function execute(array $args): void
    {
        $databaseAlias = $this->getOptionValue($args, '--database') ?: $this->bootstrap->getConfig()->getDefaultDatabaseAlias();
        $fromDatabase = $this->hasFlag($args, '--from-database');
        $fakeIt = $this->hasFlag($args, '--fake');
        $schema = $this->getOptionValue($args, '--schema');

        if ($fromDatabase) {
            $this->executeFromDatabase($databaseAlias, $args, $fakeIt);
            return;
        }

        $this->executeFromModels($databaseAlias, $args);
    }

    private function executeFromDatabase(string $databaseAlias, array $args, bool $fakeApply = false): void
    {
        $schema = $this->getOptionValue($args, '--schema');
        $this->info("Reverse engineering from database '{$databaseAlias}'" . ($schema ? " (schema: {$schema})" : "") . "...");
        
        $config = $this->bootstrap->getConfig();
        $pdo = $this->bootstrap->getPdo($databaseAlias);
        $introspector = new Introspector($pdo, $schema);
        $executor = $this->bootstrap->createExecutor($databaseAlias);
        
        // Get current database state
        $tables = $introspector->getTables();
        $currentState = $this->buildStateFromDatabase($tables);
        
        // Get previous state from migrations
        $this->info("Calculating previous state from migrations...");
        $previousState = $executor->projectStateAtApplied();
        
        $this->info("Detecting changes from database...");
        $questioner = new Questioner();
        $autodetector = new Autodetector($previousState, $currentState, $questioner);
        $changes = $autodetector->detectChanges();

        if (empty($changes)) {
            $this->info("No changes detected. Database is already in sync with migrations.");
            return;
        }

        // Validate
        $validator = new Validator();
        $stateErrors = $validator->validateState($currentState);
        $opErrors = $validator->validateOperations($changes, $currentState);
        $allErrors = array_merge($stateErrors, $opErrors);

        if (!empty($allErrors)) {
            $this->error("Validation failed!");
            foreach ($allErrors as $err) {
                echo "  - {$err}\n";
            }
            return;
        }

        $this->info("Found " . count($changes) . " changes to create as migrations.");

        $apps = $config->getApps();
        $groupedChanges = $this->groupChangesByApp($changes, $currentState, $previousState, $apps);
        $timestamp = 'm' . time();

        foreach ($groupedChanges as $appName => $appChanges) {
            $loader = $this->bootstrap->createLoader();
            $graph = $loader->getGraph();
            $sameAppDependencies = $this->buildDependencies($graph, $appName);
            $dependencies = array_values(array_unique($sameAppDependencies));
            
            $writer = new MigrationWriter(
                $appChanges,
                $dependencies,
                $timestamp,
                [],
                empty($sameAppDependencies),
                true,
                []
            );

            $filename = $apps[$appName]['migrations'] . '/' . $timestamp . '.php';
            if (!is_dir(dirname($filename))) {
                mkdir(dirname($filename), 0755, true);
            }

            file_put_contents($filename, $writer->asPhp());
            $this->info("Created migration: {$filename}");
            
            if ($fakeApply) {
                $this->info("Faking migration: {$filename}");
                $executor->recordFakeMigration($appName, $timestamp);
            }
        }
    }

    private function buildStateFromDatabase(array $tables): \Nudelsalat\Migrations\ProjectState
    {
        $schemaTables = [];
        
        foreach ($tables as $table) {
            if ($table->name === 'nudelsalat_migrations') {
                continue;
            }

            // Convert database table to ProjectState Table
            $columns = [];
            foreach ($table->getColumns() as $col) {
                $columns[$col->name] = new \Nudelsalat\Schema\Column(
                    $col->name,
                    $this->mapDbTypeToFieldType($col->type),
                    $col->nullable,
                    $col->default,
                    $col->primaryKey,
                    $col->autoIncrement
                );
            }

            $schemaTables[$table->name] = new \Nudelsalat\Schema\Table(
                $table->name,
                $columns,
                $table->options + ['app_label' => 'main'],
                $table->getIndexes(),
                $table->getConstraints(),
                $table->getForeignKeys()
            );
        }

        return new \Nudelsalat\Migrations\ProjectState(array_values($schemaTables));
    }

    private function mapDbTypeToFieldType(string $dbType): string
    {
        $type = strtolower($dbType);
        
        if (str_contains($type, 'int') || str_contains($type, 'serial')) {
            return 'int';
        }
        if (str_contains($type, 'bool')) {
            return 'boolean';
        }
        if (str_contains($type, 'decimal') || str_contains($type, 'numeric') || str_contains($type, 'money')) {
            return 'decimal';
        }
        if (str_contains($type, 'date') || str_contains($type, 'time')) {
            return 'datetime';
        }
        if (str_contains($type, 'json')) {
            return 'json';
        }
        if (str_contains($type, 'text') || str_contains($type, 'blob')) {
            return 'text';
        }
        
        // Default to string, try to extract length
        if (preg_match('/varchar\((\d+)\)/', $type, $matches)) {
            return 'varchar(' . $matches[1] . ')';
        }
        
        return 'varchar(255)';
    }

    private function executeFromModels(string $databaseAlias, array $args): void
    {
        $this->info("Inspecting models...");
        $config = $this->bootstrap->getConfig();
        $loader = $this->bootstrap->createLoader();
        $graph = $loader->getGraph();
        
        // 1. Conflict Detection (DAG multi-head check)
        foreach (array_keys($config->getApps()) as $appName) {
            $heads = $graph->find_heads($appName);
            if (count($heads) > 1) {
                $this->error("Conflicting migrations detected in app '{$appName}'!");
                echo "Multiple heads: " . implode(', ', $heads) . "\n";
                echo "Please run 'nudelsalat merge {$appName}' to reconcile divergent branches.\n";
                return;
            }
        }

        $executor = $this->bootstrap->createExecutor($databaseAlias);
        
        $modelDirs = $this->getModelDirectories($config->getApps(), $databaseAlias);

        $inspector = new Inspector();
        $currentState = $inspector->inspect($modelDirs);

        $this->info("Calculating previous state...");
        $previousState = $executor->projectStateAtApplied();

        $this->info("Detecting changes...");
        $questioner = new Questioner();
        $autodetector = new Autodetector($previousState, $currentState, $questioner);
        $changes = $autodetector->detectChanges();

        if (empty($changes)) {
            $this->info("No changes detected.");
            return;
        }

        // 2. Relational Validation (God Tier Guardrails)
        $validator = new Validator();
        $stateErrors = $validator->validateState($currentState);
        $opErrors = $validator->validateOperations($changes, $currentState);
        $allErrors = array_merge($stateErrors, $opErrors);

        if (!empty($allErrors)) {
            $this->error("Validation failed! Cannot create migrations for an invalid project state.");
            foreach ($allErrors as $err) {
                echo "  - {$err}\n";
            }
            return;
        }

        $apps = $config->getApps();
        $groupedChanges = $this->groupChangesByApp($changes, $currentState, $previousState, $apps);
        $generatedNames = [];

        $appCounters = $this->getMigrationCounters($apps, $graph);
        $firstRun = $this->isFirstMigration($graph);

        foreach (array_keys($groupedChanges) as $appName) {
            if (!isset($appCounters[$appName])) {
                $appCounters[$appName] = 1;
            }
            $counter = $appCounters[$appName]++;
            
            if ($firstRun && $counter === 1) {
                $generatedNames[$appName] = sprintf('%04d_initial', $counter);
            } else {
                $description = $this->generateDescription($groupedChanges[$appName]);
                $generatedNames[$appName] = sprintf('%04d_%s', $counter, $description);
            }
        }

        foreach ($groupedChanges as $appName => $appChanges) {
            $sameAppDependencies = $this->buildDependencies($graph, $appName);
            $crossAppDependencies = $this->buildCrossAppDependencies(
                $appName,
                $appChanges,
                $currentState,
                $previousState,
                $graph,
                $generatedNames
            );
            $dependencies = array_values(array_unique(array_merge($sameAppDependencies, $crossAppDependencies)));
            $writer = new MigrationWriter(
                $appChanges,
                $dependencies,
                $generatedNames[$appName],
                [],
                empty($sameAppDependencies),
                true,
                []
            );

            $filename = $apps[$appName]['migrations'] . '/' . $generatedNames[$appName] . '.php';
            if (!is_dir(dirname($filename))) {
                mkdir(dirname($filename), 0755, true);
            }

            file_put_contents($filename, $writer->asPhp());
            $this->info("Created migration: {$filename}");
        }
    }

    private function buildDependencies(\Nudelsalat\Migrations\Graph $graph, string $targetApp): array
    {
        $heads = $graph->find_heads($targetApp);
        sort($heads);

        return array_map(
            static fn(string $name): string => str_contains($name, '.') ? explode('.', $name, 2)[1] : $name,
            $heads
        );
    }

    private function getModelDirectories(array $apps, string $databaseAlias): array
    {
        $modelDirs = [];
        $router = $this->bootstrap->getMigrationRouter();

        foreach ($apps as $name => $paths) {
            if (!$router->allowMigrate($databaseAlias, $name)) {
                continue;
            }
            $modelDirs[$name] = $paths;
        }

        return $modelDirs;
    }

    private function groupChangesByApp(array $changes, \Nudelsalat\Migrations\ProjectState $currentState, \Nudelsalat\Migrations\ProjectState $previousState, array $apps): array
    {
        $grouped = [];
        $tableAppMap = $this->buildTableAppMap($currentState, $previousState);

        foreach ($changes as $change) {
            $appName = $this->determineOperationApp($change, $currentState, $previousState, $tableAppMap);
            if ($appName === null || !isset($apps[$appName])) {
                $appName = array_key_first($apps);
            }
            $grouped[$appName][] = $change;
        }

        return $grouped;
    }

    private function buildCrossAppDependencies(
        string $appName,
        array $changes,
        \Nudelsalat\Migrations\ProjectState $currentState,
        \Nudelsalat\Migrations\ProjectState $previousState,
        \Nudelsalat\Migrations\Graph $graph,
        array $generatedNames
    ): array {
        $dependencies = [];
        $tableAppMap = $this->buildTableAppMap($currentState, $previousState);

        foreach ($changes as $change) {
            foreach ($this->referencedApps($change, $tableAppMap) as $referencedApp) {
                if ($referencedApp === $appName) {
                    continue;
                }

                if (isset($generatedNames[$referencedApp])) {
                    $dependencies[] = $referencedApp . '.' . $generatedNames[$referencedApp];
                    continue;
                }

                foreach ($graph->find_heads($referencedApp) as $head) {
                    $dependencies[] = $head;
                }
            }
        }

        return $dependencies;
    }

    private function buildTableAppMap(\Nudelsalat\Migrations\ProjectState $currentState, \Nudelsalat\Migrations\ProjectState $previousState): array
    {
        $map = [];

        foreach ([$previousState, $currentState] as $state) {
            foreach ($state->getTables() as $tableName => $table) {
                if (isset($table->options['app_label'])) {
                    $map[$tableName] = $table->options['app_label'];
                }
            }
        }

        return $map;
    }

    private function determineOperationApp(object $operation, \Nudelsalat\Migrations\ProjectState $currentState, \Nudelsalat\Migrations\ProjectState $previousState, array $tableAppMap): ?string
    {
        if ($operation instanceof \Nudelsalat\Migrations\Operations\CreateModel) {
            return $operation->options['app_label'] ?? $tableAppMap[$operation->name] ?? null;
        }

        if ($operation instanceof \Nudelsalat\Migrations\Operations\DeleteModel) {
            return $tableAppMap[$operation->name] ?? null;
        }

        if ($operation instanceof \Nudelsalat\Migrations\Operations\RenameModel) {
            return $tableAppMap[$operation->newName] ?? $tableAppMap[$operation->oldName] ?? null;
        }

        $modelName = property_exists($operation, 'modelName') ? $operation->modelName : null;
        if ($modelName !== null) {
            return $tableAppMap[$modelName] ?? null;
        }

        return null;
    }

    private function referencedApps(object $operation, array $tableAppMap): array
    {
        $apps = [];

        if ($operation instanceof \Nudelsalat\Migrations\Operations\AddForeignKey && isset($tableAppMap[$operation->toModel])) {
            $apps[] = $tableAppMap[$operation->toModel];
        }

        return array_values(array_unique($apps));
    }

    private function getMigrationCounters(array $apps, \Nudelsalat\Migrations\Graph $graph): array
    {
        $counters = [];
        foreach (array_keys($apps) as $appName) {
            $heads = $graph->find_heads($appName);
            if (empty($heads)) {
                $counters[$appName] = 1;
            } else {
                $counters[$appName] = 0;
                foreach ($heads as $head) {
                    $num = (int) preg_replace('/[^0-9]/', '', $head);
                    $counters[$appName] = max($counters[$appName], $num);
                }
                $counters[$appName]++;
            }
        }
        return $counters;
    }

    private function isFirstMigration(\Nudelsalat\Migrations\Graph $graph): bool
    {
        return empty($graph->find_heads('main'));
    }

    private function generateDescription(array $operations): string
    {
        $descriptions = [];
        foreach ($operations as $op) {
            $parts = explode('\\', get_class($op));
            $className = end($parts);
            $descriptions[] = $this->simplifyOperationName($className, $op);
        }
        $unique = array_values(array_unique($descriptions));
        return implode('_and_', array_slice($unique, 0, 3));
    }

    private function simplifyOperationName(string $className, object $op): string
    {
        $name = strtolower(preg_replace('/([A-Z])/', '_$1', $className));
        $name = trim($name, '_');
        
        if (isset($op->name)) {
            return $name . '_' . $op->name;
        }
        if (isset($op->modelName)) {
            return $name . '_' . $op->modelName;
        }
        if (isset($op->tableName)) {
            return $name . '_' . $op->tableName;
        }
        
        return $name;
    }
}
