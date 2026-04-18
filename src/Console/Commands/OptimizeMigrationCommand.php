<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Migrations\MigrationWriter;
use Nudelsalat\Migrations\Optimizer;

class OptimizeMigrationCommand extends BaseCommand
{
    public function execute(array $args): void
    {
        $positionals = $this->getPositionalArgs($args);
        if (count($positionals) !== 1) {
            $this->error('Usage: optimize <migration_name>');
            return;
        }

        $migrationName = $positionals[0];
        $loader = $this->bootstrap->createLoader();
        $graph = $loader->getGraph();

        if (!isset($graph->nodes[$migrationName])) {
            $this->error("Migration '{$migrationName}' not found.");
            return;
        }

        $migration = $graph->nodes[$migrationName];
        $optimized = (new Optimizer())->optimize($migration->operations);
        $writer = new MigrationWriter(
            $optimized,
            $migration->dependencies,
            str_contains($migrationName, '.') ? explode('.', $migrationName, 2)[1] : $migrationName,
            $migration->replaces,
            $migration->initial,
            $migration->atomic,
            $migration->runBefore
        );

        file_put_contents($migration->path, $writer->asPhp());
        $this->info("Optimized migration: {$migration->path}");
    }
}
