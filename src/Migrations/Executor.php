<?php

namespace Nudelsalat\Migrations;

use Nudelsalat\Migrations\Routing\AllowAllMigrationRouter;
use Nudelsalat\Migrations\Routing\MigrationRouterInterface;
use Nudelsalat\Schema\SchemaEditor;

class Executor
{
    private \PDO $pdo;
    private Loader $loader;
    private Recorder $recorder;
    private SchemaEditor $schemaEditor;
    private Events\EventDispatcher $dispatcher;
    private string $databaseAlias;
    private MigrationRouterInterface $migrationRouter;
    /** @var callable|null */
    private $progressCallback;

    public function __construct(
        \PDO $pdo,
        Loader $loader,
        Recorder $recorder,
        SchemaEditor $schemaEditor,
        Events\EventDispatcher $dispatcher,
        string $databaseAlias = 'default',
        ?MigrationRouterInterface $migrationRouter = null
    ) {
        $this->pdo = $pdo;
        $this->loader = $loader;
        $this->recorder = $recorder;
        $this->schemaEditor = $schemaEditor;
        $this->dispatcher = $dispatcher;
        $this->databaseAlias = $databaseAlias;
        $this->migrationRouter = $migrationRouter ?: new AllowAllMigrationRouter();
    }

    public function setProgressCallback(callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    public function recordFakeMigration(string $appName, string $migrationName): void
    {
        $fullName = $appName . '.' . $migrationName;
        $this->recorder->ensureSchema();
        $this->recorder->recordFakeMigration($fullName);
    }

    public function migrate(?array $targets = null, bool $fake = false, bool $fakeInitial = false): void
    {
        $this->recorder->ensureSchema();
        
        $graph = $this->loader->getGraph();
        $graph->validate();
        $applied = $this->recorder->getAppliedMigrations();
        $this->loader->ensureConsistentHistory($applied);
        if (!empty($applied)) {
            $this->loader->ensureNoConflicts($applied);
        }
        $this->ensureValidTargetPlan($targets, $applied);
        $effectiveApplied = $this->getEffectiveAppliedMigrations($applied);
        
        // Planning
        $plan = [];
        $leaves = $targets ? $this->resolveMigrateTargets($targets, $applied) : $this->loader->getActiveLeafNodes($applied);
        
        foreach ($leaves as $target) {
            foreach ($this->buildForwardsPlan($target, $applied) as $node) {
                if (!in_array($node, $effectiveApplied, true) && !isset($plan[$node])) {
                    $plan[$node] = $graph->nodes[$node];
                }
            }
        }

        foreach ($plan as $name => $migration) {
            $this->applyMigration($migration, $fake, $fakeInitial);
        }

        $this->pruneStaleReplacementRecords();
    }

    private function applyMigration(Migration $migration, bool $fake = false, bool $fakeInitial = false): void
    {
        $this->recorder->ensureSchema();
        $checksums = $this->recorder->getAppliedChecksums();
        $currentChecksum = $this->calculateChecksum($migration);

        // 1. Check for Integrity Drift
        if (isset($checksums[$migration->name]) && $checksums[$migration->name] !== $currentChecksum) {
            throw new \Nudelsalat\Migrations\Exceptions\IntegrityError(
                "Migration history integrity error! The file for '{$migration->name}' has been modified " .
                "since it was applied. Database: {$checksums[$migration->name]}, Disk: {$currentChecksum}"
            );
        }

        $writer = new \Nudelsalat\Console\ConsoleWriter();
        if ($this->progressCallback) {
            ($this->progressCallback)('apply_start', $migration);
        } else {
            $writer->write("  Applying {$migration->name}...", 'bold');
        }

        $isFake = $fake;
        
        if (!$isFake && $fakeInitial && $this->isInitial($migration)) {
            if ($this->detectSoftApplied($migration)) {
                $isFake = true;
            }
        }

        if (!$this->shouldRunMigration($migration)) {
            if (!isset($checksums[$migration->name])) {
                $this->recorder->recordApplied($migration->name, $currentChecksum);
            }

            if ($this->progressCallback) {
                ($this->progressCallback)('apply_success', $migration, true);
            }
            return;
        }

        $useTransaction = $this->shouldUseTransaction($migration);
        
        if ($useTransaction && !$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }

        try {
            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $event = new Events\MigrationEvent($migration, $driver);
            $this->dispatcher->dispatch(Events\MigrationEvent::PRE_MIGRATE, $event);

            if (!$isFake) {
                // Log individual operations for premium feel
                foreach ($migration->operations as $op) {
                   $writer->write("\n    -> " . $op->describe(), 'blue');
                }
                echo "\n";

                $state = $this->projectStateAtApplied($migration->name);
                $registry = new StateRegistry($state, $this->pdo);
                $migration->apply($state, $this->schemaEditor, $registry);
                $this->schemaEditor->flushDeferredSql();
            }
            $this->recorder->recordApplied($migration->name, $currentChecksum);
            $this->dispatcher->dispatch(Events\MigrationEvent::POST_MIGRATE, $event);
            
            if ($useTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            
            if ($this->progressCallback) {
                ($this->progressCallback)('apply_success', $migration, $isFake);
            } else {
                $status = $isFake ? "FAKE SUCCESS" : "OK";
                $writer->info(" " . $status);
            }
        } catch (\Throwable $e) {
            if ($useTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (!$this->progressCallback) {
                $writer->error(" FAILED: " . $e->getMessage());
            }
            throw $e;
        }
    }

    private function calculateChecksum(Migration $migration): string
    {
        return hash_file('sha256', $migration->path);
    }

    private function isInitial(Migration $migration): bool
    {
        return $migration->initial || str_contains($migration->name, 'initial') || empty($migration->dependencies);
    }

    private function detectSoftApplied(Migration $migration): bool
    {
        $operations = $this->collectSoftApplyOperations($migration->operations);
        $foundRelevantOperation = false;

        foreach ($operations as $op) {
            if ($op instanceof \Nudelsalat\Migrations\Operations\CreateModel) {
                $foundRelevantOperation = true;

                if (!$this->tableExists($op->name)) {
                    return false;
                }

                foreach ($op->fields as $field) {
                    if (!$this->columnExists($op->name, $field->name)) {
                        return false;
                    }
                }
            }

            if ($op instanceof \Nudelsalat\Migrations\Operations\AddField) {
                $foundRelevantOperation = true;

                if (!$this->tableExists($op->modelName) || !$this->columnExists($op->modelName, $op->name)) {
                    return false;
                }
            }
        }

        return $foundRelevantOperation;
    }

    /** @param array<int, object> $operations * @return array<int, object> */
    private function collectSoftApplyOperations(array $operations): array
    {
        $collected = [];

        foreach ($operations as $operation) {
            if ($operation instanceof \Nudelsalat\Migrations\Operations\SeparateDatabaseAndState) {
                // Recursively collect from both database and state operations
                $collected = array_merge(
                    $collected, 
                    $this->collectSoftApplyOperations($operation->databaseOperations)
                );
                // Also check state operations for CreateModel/AddField
                $collected = array_merge(
                    $collected,
                    $this->collectSoftApplyOperations($operation->stateOperations ?? [])
                );
                continue;
            }

            $collected[] = $operation;
        }

        return $collected;
    }

    private function tableExists(string $name): bool
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'sqlite' => $this->sqliteTableExists($name),
            'pgsql' => $this->postgresTableExists($name),
            default => $this->genericTableExists($name),
        };
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'sqlite' => $this->sqliteColumnExists($tableName, $columnName),
            'pgsql' => $this->postgresColumnExists($tableName, $columnName),
            default => $this->genericColumnExists($tableName, $columnName),
        };
    }

    private function genericTableExists(string $name): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$this->quoteIdentifier($name)} LIMIT 1");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function genericColumnExists(string $tableName, string $columnName): bool
    {
        try {
            $this->pdo->query(
                "SELECT {$this->quoteIdentifier($columnName)} FROM {$this->quoteIdentifier($tableName)} LIMIT 1"
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function sqliteTableExists(string $name): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->execute(['name' => $name]);
        return (bool) $stmt->fetchColumn();
    }

    private function sqliteColumnExists(string $tableName, string $columnName): bool
    {
        if (!$this->sqliteTableExists($tableName)) {
            return false;
        }

        $stmt = $this->pdo->query("PRAGMA table_info(\"{$tableName}\")");
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === $columnName) {
                return true;
            }
        }

        return false;
    }

    private function postgresTableExists(string $name): bool
    {
        [$schema, $table] = $this->splitQualifiedName($name);
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table LIMIT 1'
        );
        $stmt->execute(['schema' => $schema, 'table' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    private function postgresColumnExists(string $tableName, string $columnName): bool
    {
        [$schema, $table] = $this->splitQualifiedName($tableName);
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table AND column_name = :column LIMIT 1'
        );
        $stmt->execute(['schema' => $schema, 'table' => $table, 'column' => $columnName]);
        return (bool) $stmt->fetchColumn();
    }

    private function splitQualifiedName(string $name): array
    {
        if (str_contains($name, '.')) {
            return explode('.', $name, 2);
        }

        return ['public', $name];
    }

    private function quoteIdentifier(string $name): string
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            if (str_contains($name, '.')) {
                $parts = explode('.', $name);
                return '"' . implode('"."', array_map(static fn(string $part): string => str_replace('"', '""', $part), $parts)) . '"';
            }

            return '"' . str_replace('"', '""', $name) . '"';
        }

        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function projectStateAtApplied(?string $migrationName = null): ProjectState
    {
        $state = new ProjectState();
        $graph = $this->loader->getGraph();
        $graph->validate();
        $applied = $this->recorder->getAppliedMigrations();
        $this->loader->ensureConsistentHistory($applied);
        $effectiveApplied = $this->getEffectiveAppliedMigrations($applied);

        $allMigrations = [];
        foreach ($this->loader->getActiveLeafNodes($applied) as $leaf) {
            foreach ($this->buildForwardsPlan($leaf, $applied) as $migration) {
                if (!in_array($migration, $allMigrations, true)) {
                    $allMigrations[] = $migration;
                }
            }
        }
        $plan = [];

        if ($migrationName) {
            $plan = $this->buildForwardsPlan($migrationName, $applied);
            array_pop($plan); // Don't include the target itself
        } else {
            // Include everything currently applied in correct dependency order
            foreach ($allMigrations as $name) {
                if ($this->shouldApplyToProjectState($name, $applied, $effectiveApplied) && $this->shouldRunMigration($graph->nodes[$name])) {
                    $plan[] = $name;
                }
            }
        }

        foreach ($plan as $name) {
            if (isset($graph->nodes[$name]) && $this->shouldRunMigration($graph->nodes[$name])) {
                $graph->nodes[$name]->mutateState($state);
            }
        }

        return $state;
    }

    public function unmigrate(string $target, bool $fake = false): void
    {
        $graph = $this->loader->getGraph();
        $graph->validate();
        $applied = $this->recorder->getAppliedMigrations();
        $this->loader->ensureConsistentHistory($applied);
        $effectiveApplied = $this->getEffectiveAppliedMigrations($applied);
        
        if (!in_array($target, $effectiveApplied, true)) {
            // Try partial match
            foreach ($effectiveApplied as $appliedName) {
                if (str_ends_with($appliedName, $target) || stripos($appliedName, $target) !== false) {
                    $target = $appliedName;
                    break;
                }
            }
        }
        
        if (!in_array($target, $effectiveApplied, true)) {
            echo "Migration '{$target}' is not applied.\n";
            return;
        }

        $toUnapply = [];
        $appliedSet = array_fill_keys($applied, true);
        foreach ($this->resolveUnmigrateTargets($target, $applied, $effectiveApplied) as $resolvedTarget) {
            $candidatePlan = $this->loader->isNodeActive($resolvedTarget, $applied)
                ? $this->buildBackwardsPlan($resolvedTarget, $applied)
                : $graph->backwards_plan($resolvedTarget);

            foreach ($candidatePlan as $candidate) {
                if (
                    isset($appliedSet[$candidate])
                    && !in_array($candidate, $toUnapply, true)
                ) {
                    $toUnapply[] = $candidate;
                }
            }
        }

        foreach ($toUnapply as $name) {
            $migration = $graph->nodes[$name];
            
            if ($this->progressCallback) {
                ($this->progressCallback)('unapply_start', $migration);
            }

            if (!$fake && !$this->shouldRunMigration($migration)) {
                $this->recorder->recordUnapplied($name);
                if ($this->progressCallback) {
                    ($this->progressCallback)('unapply_success', $migration);
                }
                continue;
            }

            $skipDatabaseRollback = !$fake && $this->isRedundantReplacementRecord($migration, $applied);

            $useTransaction = $this->shouldUseTransaction($migration);

            if ($useTransaction && !$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }
            try {
                if (!$fake && !$skipDatabaseRollback) {
                    $state = $this->projectStateAtApplied($name);
                    $registry = new StateRegistry($state, $this->pdo);
                    $migration->unapply($state, $this->schemaEditor, $registry);
                    $this->schemaEditor->flushDeferredSql();
                }
                $this->recorder->recordUnapplied($name);
                if ($this->progressCallback) {
                    ($this->progressCallback)('unapply_success', $migration);
                }
                if ($useTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($useTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }

        $this->pruneStaleReplacementRecords();
    }

    /** @param string[] $applied */
    private function getEffectiveAppliedMigrations(array $applied): array
    {
        $effective = array_fill_keys($applied, true);

        foreach ($this->loader->getReplacementMap() as $replacement => $replacedMigrations) {
            $allReplacedApplied = true;
            foreach ($replacedMigrations as $replacedMigration) {
                if (!isset($effective[$replacedMigration])) {
                    $allReplacedApplied = false;
                    break;
                }
            }

            if (isset($effective[$replacement]) || $allReplacedApplied) {
                $effective[$replacement] = true;
                foreach ($replacedMigrations as $replacedMigration) {
                    $effective[$replacedMigration] = true;
                }
            }
        }

        return array_keys($effective);
    }

    /** @param string[] $applied @param string[] $effectiveApplied */
    private function shouldApplyToProjectState(string $migrationName, array $applied, array $effectiveApplied): bool
    {
        if (!in_array($migrationName, $effectiveApplied, true)) {
            return false;
        }

        $replacementMap = $this->loader->getReplacementMap();
        $replacedByMap = $this->loader->getReplacedByMap();

        if (isset($replacementMap[$migrationName])) {
            foreach ($replacementMap[$migrationName] as $replacedMigration) {
                if (in_array($replacedMigration, $applied, true)) {
                    return !in_array($migrationName, $applied, true);
                }
            }

            return in_array($migrationName, $applied, true);
        }

        $replacement = $replacedByMap[$migrationName] ?? null;
        if ($replacement !== null && in_array($replacement, $effectiveApplied, true)) {
            return !in_array($replacement, $effectiveApplied, true);
        }

        return in_array($migrationName, $applied, true);
    }

    private function pruneStaleReplacementRecords(): void
    {
        $applied = $this->recorder->getAppliedMigrations();
        $appliedSet = array_fill_keys($applied, true);

        foreach ($this->loader->getReplacementMap() as $replacement => $replacedMigrations) {
            if (!isset($appliedSet[$replacement])) {
                continue;
            }

            $appliedReplacedCount = 0;
            foreach ($replacedMigrations as $replacedMigration) {
                if (isset($appliedSet[$replacedMigration])) {
                    $appliedReplacedCount++;
                }
            }

            if ($appliedReplacedCount > 0 && $appliedReplacedCount < count($replacedMigrations)) {
                $this->recorder->recordUnapplied($replacement);
            }
        }
    }

    /** @param string[] $applied */
    private function isRedundantReplacementRecord(Migration $migration, array $applied): bool
    {
        $replacementMap = $this->loader->getReplacementMap();
        if (!isset($replacementMap[$migration->name])) {
            return false;
        }

        $appliedSet = array_fill_keys($applied, true);
        $appliedReplacedCount = 0;

        foreach ($replacementMap[$migration->name] as $replacedMigration) {
            if (isset($appliedSet[$replacedMigration])) {
                $appliedReplacedCount++;
            }
        }

        return $appliedReplacedCount > 0;
    }

    private function shouldUseTransaction(Migration $migration): bool
    {
        if (!$migration->atomic) {
            return false;
        }

        if (!$this->schemaEditor->supportsTransactions()) {
            return false;
        }

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        foreach ($migration->operations as $op) {
            if (!$this->operationCanBeInTransaction($op, $driver)) {
                return false;
            }
        }

        return true;
    }

    private function operationCanBeInTransaction(object $operation, string $driver): bool
    {
        $class = get_class($operation);

        if ($driver === 'mysql') {
            $nonTransactionalOps = [
                'Nudelsalat\Migrations\Operations\CreateModel',
                'Nudelsalat\Migrations\Operations\DeleteModel',
                'Nudelsalat\Migrations\Operations\RenameModel',
                'Nudelsalat\Migrations\Operations\AlterModelTable',
            ];

            if (in_array($class, $nonTransactionalOps, true)) {
                return false;
            }
        }

        return true;
    }

    private function shouldRunMigration(Migration $migration): bool
    {
        $appLabel = str_contains($migration->name, '.') ? explode('.', $migration->name, 2)[0] : 'default';
        return $this->migrationRouter->allowMigrate($this->databaseAlias, $appLabel, $migration);
    }

    /** @param string[]|null $targets @param string[] $applied */
    private function ensureValidTargetPlan(?array $targets, array $applied): void
    {
        if ($targets === null || count($targets) < 2) {
            return;
        }

        $activeApplied = array_fill_keys($this->getEffectiveAppliedMigrations($applied), true);
        $hasAppliedTarget = false;
        $hasUnappliedTarget = false;

        foreach ($targets as $target) {
            if (isset($activeApplied[$target])) {
                $hasAppliedTarget = true;
            } else {
                $hasUnappliedTarget = true;
            }
        }

        if ($hasAppliedTarget && $hasUnappliedTarget) {
            throw new \Nudelsalat\Migrations\Exceptions\InvalidMigrationPlan(
                'Mixed migration plan detected: requested targets require both applying and unapplying migrations in one run.'
            );
        }
    }

    /** @param string[] $targets @param string[] $applied */
    private function resolveMigrateTargets(array $targets, array $applied): array
    {
        $resolved = [];

        foreach ($targets as $target) {
            $resolvedTarget = $this->resolveMigrateTarget($target, $applied);
            foreach ($resolvedTarget as $name) {
                if (!in_array($name, $resolved, true)) {
                    $resolved[] = $name;
                }
            }
        }

        return $resolved;
    }

    /** @param string[] $applied */
    private function resolveMigrateTarget(string $target, array $applied): array
    {
        if ($this->loader->isNodeActive($target, $applied)) {
            return [$target];
        }

        $replacementMap = $this->loader->getReplacementMap();
        if (!isset($replacementMap[$target])) {
            return [$target];
        }

        $activeHeads = [];
        $replacedSet = array_fill_keys($replacementMap[$target], true);

        foreach ($replacementMap[$target] as $candidate) {
            if (!$this->loader->isNodeActive($candidate, $applied)) {
                continue;
            }

            $hasActiveChildInsideReplacement = false;
            foreach ($this->loader->getActiveChildren($candidate, $applied) as $child) {
                if (isset($replacedSet[$child])) {
                    $hasActiveChildInsideReplacement = true;
                    break;
                }
            }

            if (!$hasActiveChildInsideReplacement) {
                $activeHeads[] = $candidate;
            }
        }

        return $activeHeads !== [] ? $activeHeads : [$target];
    }

    /** @param string[] $applied @param string[] $effectiveApplied */
    private function resolveUnmigrateTargets(string $target, array $applied, array $effectiveApplied): array
    {
        if ($this->loader->isNodeActive($target, $applied)) {
            return [$target];
        }

        $replacedByMap = $this->loader->getReplacedByMap();
        $replacementForTarget = $replacedByMap[$target] ?? null;
        if (
            $replacementForTarget !== null
            && !in_array($target, $applied, true)
            && $this->loader->isNodeActive($replacementForTarget, $applied)
        ) {
            return [$replacementForTarget];
        }

        $replacementMap = $this->loader->getReplacementMap();
        if (!isset($replacementMap[$target])) {
            return [$target];
        }

        $roots = [];
        $replacedSet = array_fill_keys($replacementMap[$target], true);

        foreach ($replacementMap[$target] as $replacedMigration) {
            if (!in_array($replacedMigration, $effectiveApplied, true) || !$this->loader->isNodeActive($replacedMigration, $applied)) {
                continue;
            }

            $hasParentInsideReplacement = false;
            foreach ($this->loader->getActiveDependencies($replacedMigration, $applied) as $dependency) {
                if (isset($replacedSet[$dependency])) {
                    $hasParentInsideReplacement = true;
                    break;
                }
            }

            if (!$hasParentInsideReplacement) {
                $roots[] = $replacedMigration;
            }
        }

        return $roots !== [] ? $roots : [$target];
    }

    /** @param string[] $applied */
    private function buildForwardsPlan(string $target, array $applied): array
    {
        $plan = [];
        $visited = [];
        $this->visitForwards($target, $applied, $visited, $plan);
        return $plan;
    }

    /** @param string[] $applied */
    private function buildBackwardsPlan(string $target, array $applied): array
    {
        $plan = [];
        $visited = [];
        $this->visitBackwards($target, $applied, $visited, $plan);
        return array_reverse($plan);
    }

    /** @param string[] $applied @param array<string,bool> $visited @param string[] $plan */
    private function visitForwards(string $migrationName, array $applied, array &$visited, array &$plan): void
    {
        if (isset($visited[$migrationName]) || !$this->loader->isNodeActive($migrationName, $applied)) {
            return;
        }

        $visited[$migrationName] = true;

        foreach ($this->loader->getActiveDependencies($migrationName, $applied) as $dependency) {
            $this->visitForwards($dependency, $applied, $visited, $plan);
        }

        $plan[] = $migrationName;
    }

    /** @param string[] $applied @param array<string,bool> $visited @param string[] $plan */
    private function visitBackwards(string $migrationName, array $applied, array &$visited, array &$plan): void
    {
        if (isset($visited[$migrationName]) || !$this->loader->isNodeActive($migrationName, $applied)) {
            return;
        }

        $visited[$migrationName] = true;

        foreach ($this->loader->getActiveChildren($migrationName, $applied) as $child) {
            $this->visitBackwards($child, $applied, $visited, $plan);
        }

        $plan[] = $migrationName;
    }
}
