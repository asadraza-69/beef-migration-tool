<?php

namespace Nudelsalat\Console\Commands;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class SeederCommand extends BaseCommand
{
    private ?string $seederClass = null;
    private bool $fresh = false;

    public function execute(array $args): void
    {
        $databaseAlias = $this->getOptionValue($args, '--database') ?: $this->bootstrap->getConfig()->getDefaultDatabaseAlias();
        $pdo = $this->bootstrap->getPdo($databaseAlias);
        $config = $this->bootstrap->getConfig();

        $this->fresh = $this->hasFlag($args, '--fresh');
        $positional = $this->getPositionalArgs($args);

        // Get seeder directory
        $seederDir = $config->getSeedersDir() ?? $config->getBaseDir() . '/database/seeders';

        if (!is_dir($seederDir)) {
            $this->error("Seeder directory not found: {$seederDir}");
            return;
        }

        if (!empty($positional)) {
            // Run specific seeder
            $this->runSpecificSeeder($pdo, $positional[0], $seederDir);
            return;
        }

        // Run all seeders
        $this->runAllSeeders($pdo, $seederDir);
    }

    private function runAllSeeders(\PDO $pdo, string $seederDir): void
    {
        $files = glob($seederDir . '/*.php');
        
        if (empty($files)) {
            $this->error("No seeder files found in {$seederDir}");
            return;
        }

        $this->info("Running all seeders from {$seederDir}...");

        $loaded = 0;
        foreach ($files as $file) {
            require_once $file;
            $loaded++;
        }

        // Get all classes that extend Seeder
        $classes = array_filter(get_declared_classes(), function($class) {
            return is_a($class, \Nudelsalat\Database\Seeder::class, true) && $class !== \Nudelsalat\Database\Seeder::class;
        });

        sort($classes);

        foreach ($classes as $seederClass) {
            $this->info("Running: " . basename(str_replace('\\', '/', $seederClass)));
            
            try {
                $seeder = new $seederClass($pdo);
                $seeder->run();
            } catch (\Exception $e) {
                $this->error("Error in {$seederClass}: " . $e->getMessage());
            }
        }

        $this->info("Completed {$loaded} seeders");
    }

    private function runSpecificSeeder(\PDO $pdo, string $seederName, string $seederDir): void
    {
        $file = $seederDir . '/' . $seederName . '.php';
        
        if (!file_exists($file)) {
            $this->error("Seeder not found: {$seederName}");
            return;
        }

        require_once $file;

        $className = 'Database\\Seeders\\' . $seederName;
        if (!class_exists($className)) {
            // Try without namespace
            $className = basename($file, '.php');
            if (!class_exists($className)) {
                $this->error("Seeder class not found in file");
                return;
            }
        }

        $this->info("Running seeder: {$seederName}");
        
        $seeder = new $className($pdo);
        $seeder->run();

        $this->info("Seeder completed");
    }
}