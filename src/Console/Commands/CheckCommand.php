<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Migrations\Autodetector;
use Nudelsalat\Migrations\Inspector;
use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Migrations\Validator;

class CheckCommand extends BaseCommand
{
    public function execute(array $args): void
    {
        $isDeploy = $this->hasFlag($args, '--deploy');
        $databaseAlias = $this->getOptionValue($args, '--database') ?: $this->bootstrap->getConfig()->getDefaultDatabaseAlias();
        $config = $this->bootstrap->getConfig();
        $inspector = new Inspector();
        $modelDirs = $this->getModelDirectories($config->getApps(), $databaseAlias);
        
        // 1. Get current state from models
        $toState = $inspector->inspect($modelDirs);

        // 2. Relational Validation (God Tier)
        if ($isDeploy) {
            $this->info("Performing deep relational audit (--deploy)...");
            $validator = new Validator();
            $errors = $validator->validateState($toState);
            if (!empty($errors)) {
                $this->error("Relational validation failed!");
                foreach ($errors as $err) {
                    echo "  - {$err}\n";
                }
                return;
            }
            $this->info("  Relational integrity verified.");
        }
        
        // 3. Get current state from migrations
        $fromState = $this->bootstrap->createExecutor($databaseAlias)->projectStateAtApplied();
        
        // 4. Detect changes
        $detector = new Autodetector($fromState, $toState);
        $changes = $detector->detectChanges();
        
        if (count($changes) > 0) {
            $this->error("Unapplied changes detected! Please run 'nudelsalat makemigrations'.");
            echo "Pending operations: " . count($changes) . "\n";
            return;
        }
        
        $this->info("No pending changes detected. Everything is in sync.");
    }

    private function getModelDirectories(array $apps, string $databaseAlias): array
    {
        $modelDirs = [];
        $router = $this->bootstrap->getMigrationRouter();

        foreach ($apps as $name => $paths) {
            if (!$router->allowMigrate($databaseAlias, $name)) {
                continue;
            }
            $modelDirs[$name] = $paths['models'];
        }

        return $modelDirs;
    }
}
