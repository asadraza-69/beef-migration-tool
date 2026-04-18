<?php

namespace Nudelsalat\Console\Commands;

class ShowCommand extends BaseCommand
{
    public function execute(array $args): void
    {
        $databaseAlias = $this->getOptionValue($args, '--database') ?: $this->bootstrap->getConfig()->getDefaultDatabaseAlias();
        $loader = $this->bootstrap->createLoader();
        $recorder = $this->bootstrap->createRecorder($databaseAlias);
        
        $graph = $loader->getGraph();
        $applied = $recorder->getAppliedMigrations();
        $appliedSet = array_flip($applied);
        $replacementMap = $loader->getReplacementMap();
        $conflicts = $loader->detectConflicts($applied);
        $nodes = array_keys($graph->nodes);
        sort($nodes);
         
        $this->info("Migration Status ({$databaseAlias}):");
        $currentApp = null;
        foreach ($nodes as $name) {
            [$appName, $migrationName] = str_contains($name, '.') ? explode('.', $name, 2) : ['default', $name];
            if ($currentApp !== $appName) {
                $currentApp = $appName;
                echo "\n{$appName}:\n";
                if (isset($conflicts[$appName])) {
                    echo '  ! conflict heads: ' . implode(', ', $conflicts[$appName]) . "\n";
                }
            }

            $status = isset($appliedSet[$name]) ? '[X]' : '[ ]';
            $suffix = '';
            if (isset($replacementMap[$name])) {
                $suffix = ' (replaces: ' . implode(', ', $replacementMap[$name]) . ')';
            }

            echo " {$status} {$migrationName}{$suffix}\n";
        }
    }
}
