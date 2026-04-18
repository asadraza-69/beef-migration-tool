<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Migrations\MigrationWriter;

class MergeCommand extends BaseCommand
{
    public function execute(array $args): void
    {
        if (empty($args)) {
            $this->error("Usage: nudelsalat merge <app_name>");
            return;
        }

        $appName = $args[0];
        $config = $this->bootstrap->getConfig();
        $apps = $config->getApps();

        if (!isset($apps[$appName])) {
            $this->error("App '{$appName}' not found in configuration.");
            return;
        }

        $loader = $this->bootstrap->createLoader();
        $graph = $loader->getGraph();
        $recorder = $this->bootstrap->createRecorder();
        $applied = $recorder->getAppliedMigrations();
        $heads = $loader->findActiveHeads($appName, $applied);

        if (count($heads) <= 1) {
            $this->info("App '{$appName}' is already linear. No merge needed.");
            return;
        }

        $this->writer->warning("DIVERGENT BRANCHES DETECTED");
        $this->info("Found " . count($heads) . " divergent heads in '{$appName}':");
        foreach ($heads as $head) {
            echo "  - {$head}\n";
        }

        if (!$this->writer->confirm("Reconcile these branches now with a merge migration? (highly recommended)", true)) {
            $this->info("Merge cancelled.");
            return;
        }

        $defaultName = 'merge_m' . time();
        $customName = $this->writer->ask("Enter a name for this merge migration", $defaultName);
        
        $name = $customName;
        
        // Use full names for dependencies
        $writer = new MigrationWriter([], $heads, $name);
        
        $filename = $apps[$appName]['migrations'] . '/' . $name . '.php';
        if (!is_dir(dirname($filename))) {
            mkdir(dirname($filename), 0755, true);
        }
        
        file_put_contents($filename, $writer->asPhp());
        
        $this->writer->info("\n👑 SUCCESS: Branches Reconciled.");
        $this->info("Created merge migration: {$filename}");
    }
}
