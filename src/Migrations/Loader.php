<?php

namespace Nudelsalat\Migrations;

class Loader
{
    private Graph $graph;

    /** @var array<string, string[]> */
    private array $replacementMap = [];

    /** @var array<string, string> */
    private array $replacedByMap = [];

    public function __construct(?Graph $graph = null)
    {
        $this->graph = $graph ?: new Graph();
    }

    /**
     * @param array $appDirectories Mapping of [appName => migrationDirectory]
     */
    public function loadDisk(array $appDirectories): Graph
    {
        foreach ($appDirectories as $appName => $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = glob($directory . '/*.php');
            sort($files);

            foreach ($files as $file) {
                $migration = require $file;
                if (!$migration instanceof Migration) {
                    continue;
                }

                $baseName = basename($file, '.php');
                $fullName = "{$appName}.{$baseName}";
                $migration->name = $fullName;
                $migration->path = realpath($file);
                $migration->dependencies = array_map(
                    fn(string $parent): string => $this->qualifyName($appName, $parent),
                    $migration->dependencies
                );
                $migration->replaces = array_map(
                    fn(string $replaced): string => $this->qualifyName($appName, $replaced),
                    $migration->replaces
                );
                $migration->runBefore = array_map(
                    fn(string $target): string => $this->qualifyName($appName, $target),
                    $migration->runBefore
                );

                $this->graph->addNode($fullName, $migration);
            }
        }

        $this->buildReplacementMaps();

        foreach ($this->graph->nodes as $fullName => $migration) {
            foreach ($migration->dependencies as $parent) {
                $resolvedParent = $this->replacedByMap[$parent] ?? $parent;
                if ($resolvedParent === $fullName) {
                    continue;
                }
                $this->graph->addDependency($fullName, $resolvedParent);
            }

            foreach ($migration->runBefore as $target) {
                if ($target === $fullName) {
                    continue;
                }
                $this->graph->addDependency($target, $fullName);
            }
        }

        $this->graph->validate();

        return $this->graph;
    }

    public function getGraph(): Graph
    {
        return $this->graph;
    }

    /** @param string[] $applied @return array<string, string[]> */
    public function detectConflicts(array $applied = []): array
    {
        $conflicts = [];

        foreach ($this->getAppNames() as $appName) {
            $heads = $this->findActiveHeads($appName, $applied);
            if (count($heads) > 1) {
                $conflicts[$appName] = $heads;
            }
        }

        return $conflicts;
    }

    /** @param string[] $applied */
    public function ensureNoConflicts(array $applied = []): void
    {
        $conflicts = $this->detectConflicts($applied);
        if ($conflicts === []) {
            return;
        }

        $messages = [];
        foreach ($conflicts as $appName => $heads) {
            $messages[] = "{$appName}: " . implode(', ', $heads);
        }

        throw new \Nudelsalat\Migrations\Exceptions\ConflictError(
            'Conflicting migration branches detected. Merge required for ' . implode('; ', $messages)
        );
    }

    /** @param string[] $applied */
    public function ensureConsistentHistory(array $applied = []): void
    {
        $appliedSet = array_fill_keys($applied, true);

        foreach ($this->replacementMap as $replacement => $replacedMigrations) {
            if (!isset($appliedSet[$replacement])) {
                continue;
            }

            $appliedReplaced = array_values(array_filter(
                $replacedMigrations,
                static fn(string $migration): bool => isset($appliedSet[$migration])
            ));

            if ($appliedReplaced !== [] && count($appliedReplaced) !== count($replacedMigrations)) {
                throw new \Nudelsalat\Migrations\Exceptions\InconsistentMigrationHistory(
                    "Replacement migration '{$replacement}' is applied alongside only part of its replaced history."
                );
            }
        }

        foreach ($applied as $migrationName) {
            if (!isset($this->graph->nodes[$migrationName])) {
                throw new \Nudelsalat\Migrations\Exceptions\InconsistentMigrationHistory(
                    "Applied migration '{$migrationName}' is not present on disk."
                );
            }

            $migration = $this->graph->nodes[$migrationName];
            foreach ($migration->dependencies as $dependency) {
                $replacement = $this->replacedByMap[$dependency] ?? null;
                $dependencySatisfied = isset($appliedSet[$dependency])
                    || ($replacement !== null && isset($appliedSet[$replacement]));

                if (!$dependencySatisfied) {
                    throw new \Nudelsalat\Migrations\Exceptions\InconsistentMigrationHistory(
                        "Migration '{$migrationName}' is applied before its dependency '{$dependency}'."
                    );
                }
            }
        }
    }

    /** @return array<string, string[]> */
    public function getReplacementMap(): array
    {
        return $this->replacementMap;
    }

    /** @return array<string, string> */
    public function getReplacedByMap(): array
    {
        return $this->replacedByMap;
    }

    /** @param string[] $applied */
    public function getActiveLeafNodes(array $applied = []): array
    {
        $leaves = [];
        $activeNodes = $this->getActiveNodeNames($applied);
        $activeSet = array_fill_keys($activeNodes, true);

        foreach ($activeNodes as $name) {
            $hasActiveChild = false;
            foreach ($this->getActiveChildren($name, $applied) as $child) {
                if (isset($activeSet[$child])) {
                    $hasActiveChild = true;
                    break;
                }
            }

            if ($hasActiveChild) {
                continue;
            }

            $leaves[] = $name;
        }

        return $leaves;
    }

    /** @param string[] $applied */
    public function findActiveHeads(string $appName, array $applied = []): array
    {
        $heads = [];
        $activeNodes = array_fill_keys($this->getActiveNodeNames($applied), true);

        foreach (array_keys($this->graph->nodes) as $name) {
            if (!isset($activeNodes[$name]) || !str_starts_with($name, $appName . '.')) {
                continue;
            }

            $hasChildInApp = false;
            foreach ($this->getActiveChildren($name, $applied) as $child) {
                if (str_starts_with($child, $appName . '.')) {
                    $hasChildInApp = true;
                    break;
                }
            }

            if (!$hasChildInApp) {
                $heads[] = $name;
            }
        }

        sort($heads);

        return $heads;
    }

    /** @param string[] $applied */
    public function getActiveNodeNames(array $applied = []): array
    {
        $active = [];

        foreach (array_keys($this->graph->nodes) as $name) {
            if ($this->isNodeActive($name, $applied)) {
                $active[] = $name;
            }
        }

        sort($active);

        return $active;
    }

    /** @param string[] $applied */
    public function isNodeActive(string $migrationName, array $applied = []): bool
    {
        if (isset($this->replacementMap[$migrationName])) {
            return $this->shouldUseReplacement($migrationName, $applied);
        }

        $replacement = $this->replacedByMap[$migrationName] ?? null;
        if ($replacement === null) {
            return true;
        }

        return !$this->shouldUseReplacement($replacement, $applied);
    }

    /** @param string[] $applied */
    public function getActiveDependencies(string $migrationName, array $applied = []): array
    {
        $migration = $this->graph->nodes[$migrationName] ?? null;
        if ($migration === null) {
            return [];
        }

        $dependencies = [];
        foreach ($migration->dependencies as $parent) {
            $resolvedParent = $parent;
            $replacement = $this->replacedByMap[$parent] ?? null;
            if ($replacement !== null && $this->shouldUseReplacement($replacement, $applied)) {
                $resolvedParent = $replacement;
            }

            if ($resolvedParent === $migrationName) {
                continue;
            }

            if ($this->isNodeActive($resolvedParent, $applied)) {
                $dependencies[] = $resolvedParent;
            }
        }

        return array_values(array_unique($dependencies));
    }

    /** @param string[] $applied */
    public function getActiveChildren(string $migrationName, array $applied = []): array
    {
        $children = [];

        foreach ($this->getActiveNodeNames($applied) as $candidate) {
            if (in_array($migrationName, $this->getActiveDependencies($candidate, $applied), true)) {
                $children[] = $candidate;
            }
        }

        return $children;
    }

    private function qualifyName(string $appName, string $migrationName): string
    {
        return str_contains($migrationName, '.') ? $migrationName : "{$appName}.{$migrationName}";
    }

    /** @return string[] */
    private function getAppNames(): array
    {
        $apps = [];
        foreach (array_keys($this->graph->nodes) as $name) {
            $apps[] = explode('.', $name, 2)[0];
        }

        return array_values(array_unique($apps));
    }

    private function buildReplacementMaps(): void
    {
        $this->replacementMap = [];
        $this->replacedByMap = [];

        foreach ($this->graph->nodes as $name => $migration) {
            if ($migration->replaces === []) {
                continue;
            }

            $this->replacementMap[$name] = $migration->replaces;
            foreach ($migration->replaces as $replaced) {
                $this->replacedByMap[$replaced] = $name;
            }
        }
    }

    /** @param string[] $applied */
    private function shouldUseReplacement(string $replacement, array $applied): bool
    {
        $replaced = $this->replacementMap[$replacement] ?? [];
        if ($replaced === []) {
            return false;
        }

        $appliedSet = array_fill_keys($applied, true);
        if (isset($appliedSet[$replacement])) {
            return true;
        }

        foreach ($replaced as $migrationName) {
            if (isset($appliedSet[$migrationName])) {
                return false;
            }
        }

        return true;
    }
}
