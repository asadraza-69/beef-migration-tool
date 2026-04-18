<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Migrations\Migration;

class MigrateCommand extends BaseCommand
{
    public function execute(array $args): void
    {
        $fake = $this->hasFlag($args, '--fake');
        $fakeInitial = $this->hasFlag($args, '--fake-initial');
        $check = $this->hasFlag($args, '--check');
        $unapply = $this->hasFlag($args, '--unapply') || $this->hasFlag($args, '--rollback');
        $databaseAlias = $this->getOptionValue($args, '--database') ?: $this->bootstrap->getConfig()->getDefaultDatabaseAlias();
        $targets = $this->getPositionalArgs($args);
        
        $executor = $this->bootstrap->createExecutor($databaseAlias);
        $loader = $this->bootstrap->createLoader();
        $applied = $this->bootstrap->createRecorder($databaseAlias)->getAppliedMigrations();
        $resolvedTargets = $this->resolveTargets($targets, $loader, $applied);
        
        $action = $unapply ? "unapply" : "apply";
        
        // Setup progress callback
        $executor->setProgressCallback(function(string $event, Migration $migration, bool $fakeStatus = false) use ($check, $action) {
            $name = $migration->name;
            if ($event === 'apply_start' || $event === 'unapply_start') {
                if ($check) {
                    echo "[CHECK] Would {$action} {$name}\n";
                } else {
                    $verb = ($event === 'unapply_start') ? 'Rolling back' : 'Applying';
                    echo " {$verb} {$name}... ";
                }
            } elseif ($event === 'apply_success' || $event === 'unapply_success') {
                if ($check) {
                    echo "  --> OK\n";
                } else {
                    $msg = $fakeStatus ? "FAKED" : "OK";
                    echo "\033[32m{$msg}\033[0m\n";
                }
            }
        });

        $this->info(($unapply ? "Rolling back migrations" : "Running migrations") . " on database '{$databaseAlias}'...");
        try {
            if ($check) {
                // : show what would happen, exit with non-zero if pending
                $graph = $loader->getGraph();
                $pending = [];
                foreach ($graph->nodes as $name => $migration) {
                    if (!in_array($name, $applied, true)) {
                        $pending[] = $name;
                    }
                }
                if ($pending) {
                    $this->error("Unapplied migrations:");
                    sort($pending);
                    foreach ($pending as $name) {
                        $this->writer->write("  " . $name);
                    }
                    exit(1);
                } else {
                    $this->info("No unapplied migrations.");
                }
                return;
            }
            if ($unapply) {
                if (count($resolvedTargets) !== 1) {
                    throw new \InvalidArgumentException('Rollback requires exactly one target.');
                }
                $executor->unmigrate($resolvedTargets[0], $fake);
            } else {
                $executor->migrate($resolvedTargets !== [] ? $resolvedTargets : null, $fake, $fakeInitial);
            }
            $this->info("Done!");
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            exit(1);
        }
    }

    private function resolveTargets(array $targets, \Nudelsalat\Migrations\Loader $loader, array $applied): array
    {
        if ($targets === []) {
            return [];
        }

        $graph = $loader->getGraph();
        $resolved = [];

        foreach ($targets as $target) {
            // Direct match
            if (isset($graph->nodes[$target])) {
                $resolved[] = $target;
                continue;
            }
            
            // Full name match (e.g., "main.0007" should work)
            foreach ($graph->nodes as $name => $migration) {
                if ($name === $target || str_starts_with($name, $target)) {
                    $resolved[] = $name;
                    continue 2;
                }
            }
            
            // Partial match - e.g., "0007" or "create_model_posts" matches "main.0007_create_model_posts..."
            foreach ($graph->nodes as $name => $migration) {
                if (str_contains($name, $target)) {
                    $resolved[] = $name;
                    continue 2;
                }
            }

            $appHeads = $loader->findActiveHeads($target, $applied);
            if ($appHeads !== []) {
                if (count($appHeads) > 1) {
                    throw new \Nudelsalat\Migrations\Exceptions\ConflictError(
                        "App '{$target}' has multiple active heads: " . implode(', ', $appHeads)
                    );
                }
                $resolved[] = $appHeads[0];
                continue;
            }

            throw new \InvalidArgumentException("Unknown migration target '{$target}'. Use a full migration name or app name.");
        }

        return array_values(array_unique($resolved));
    }
}
