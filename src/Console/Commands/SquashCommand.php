<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Migrations\Squasher;
use Nudelsalat\Migrations\MigrationWriter;

class SquashCommand extends BaseCommand
{
    public function execute(array $args): void
    {
        if (count($args) < 2) {
            $this->error("Usage: squash <migration1> <migration2> ...");
            return;
        }

        $targets = $args;
        $loader = $this->bootstrap->createLoader();
        $graph = $loader->getGraph();
        
        $toSquash = [];
        foreach ($targets as $t) {
            if (!isset($graph->nodes[$t])) {
                $this->error("Migration $t not found.");
                return;
            }
            $toSquash[] = $graph->nodes[$t];
        }

        $this->info("Squashing " . count($toSquash) . " migrations...");
        $squasher = new Squasher();
        $name = 'squashed_' . time();
        $squashed = $squasher->squash($toSquash, $name);
        
        $writer = new MigrationWriter(
            $squashed->operations,
            $squashed->dependencies,
            $name,
            $squashed->replaces,
            $squashed->initial,
            $squashed->atomic,
            $squashed->runBefore
        );
        
        $config = $this->bootstrap->getConfig();
        $apps = $config->getApps();
        $targetApp = 'main'; // Simplification
        if (!isset($apps[$targetApp])) $targetApp = array_key_first($apps);

        $filename = $apps[$targetApp]['migrations'] . '/' . $name . '.php';
        
        file_put_contents($filename, $writer->asPhp());
        $this->info("Created squashed migration: {$filename}");
        $this->info("IMPORTANT: You should now delete the old migration files.");
    }
}
