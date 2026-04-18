<?php

namespace Nudelsalat\Migrations;

use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\DeleteModel;
use Nudelsalat\Migrations\Operations\RemoveField;

class Squasher
{
    /**
     * @param Migration[] $migrations
     */
    public function squash(array $migrations, string $newName): Migration
    {
        $allOperations = [];
        $allAtomic = true;
        $allRunBefore = [];
        foreach ($migrations as $migration) {
            $allAtomic = $allAtomic && $migration->atomic;
            foreach ($migration->runBefore as $target) {
                $allRunBefore[] = $target;
            }
            foreach ($migration->operations as $operation) {
                $allOperations[] = $operation;
            }
        }

        $sortedOperations = $this->topologicalSort($migrations, $allOperations);

        $squashed = new class($newName) extends Migration {
            public function __construct(string $name) { parent::__construct($name); }
        };
        $squashed->operations = $sortedOperations;
        $squashed->atomic = $allAtomic;
        
        $squashedNames = array_map(fn($m) => $m->name, $migrations);
        $allDeps = [];
        foreach ($migrations as $migration) {
            foreach ($migration->dependencies as $dep) {
                if (!in_array($dep, $squashedNames)) {
                    $allDeps[] = $dep;
                }
            }
        }
        $squashed->dependencies = array_unique($allDeps);
        $squashed->replaces = $squashedNames;
        $squashed->runBefore = array_values(array_unique(array_filter(
            $allRunBefore,
            static fn(string $target): bool => !in_array($target, $squashedNames, true)
        )));
        $squashed->initial = $squashed->dependencies === [];

        return $squashed;
    }

    /**
     * Sort operations by migration dependencies to preserve order.
     * @param Migration[] $migrations
     * @param array<int, object> $operations
     * @return array<int, object>
     */
    private function topologicalSort(array $migrations, array $operations): array
    {
        if (count($migrations) <= 1) {
            return $operations;
        }

        $migrationNames = array_map(fn($m) => $m->name, $migrations);
        $nameToMigration = [];
        foreach ($migrations as $migration) {
            $nameToMigration[$migration->name] = $migration;
        }

        $inDegree = array_fill_keys($migrationNames, 0);
        $adjacency = array_fill_keys($migrationNames, []);
        
        foreach ($migrations as $migration) {
            foreach ($migration->dependencies as $dep) {
                if (isset($inDegree[$dep])) {
                    $inDegree[$migration->name]++;
                    $adjacency[$dep][] = $migration->name;
                }
            }
        }

        $sortedMigrationNames = [];
        $queue = [];
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        while (!empty($queue)) {
            $current = array_shift($queue);
            $sortedMigrationNames[] = $current;
            
            foreach ($adjacency[$current] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        $sortedOps = [];
        foreach ($sortedMigrationNames as $name) {
            $migration = $nameToMigration[$name];
            foreach ($migration->operations as $op) {
                $sortedOps[] = $op;
            }
        }

        return $sortedOps;
    }
}
