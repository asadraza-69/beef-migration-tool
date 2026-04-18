<?php

namespace Nudelsalat\Migrations;

class Graph
{
    /** @var Migration[] Map of [migrationName => Migration] */
    public array $nodes = [];

    /** @var array Map of [childName => parentNames[]] */
    public array $dependencies = [];

    /** @var array Map of [parentName => childNames[]] */
    public array $children = [];

    public function addNode(string $name, Migration $migration): void
    {
        $this->nodes[$name] = $migration;
    }

    public function addDependency(string $child, string $parent): void
    {
        $this->dependencies[$child][] = $parent;
        $this->children[$parent][] = $child;
    }

    public function validate(): void
    {
        foreach ($this->dependencies as $child => $parents) {
            if (!isset($this->nodes[$child])) {
                throw new \Nudelsalat\Migrations\Exceptions\DependencyError(
                    "Migration graph references unknown child node '{$child}'."
                );
            }

            foreach ($parents as $parent) {
                if (!isset($this->nodes[$parent])) {
                    throw new \Nudelsalat\Migrations\Exceptions\DependencyError(
                        "Migration '{$child}' depends on missing migration '{$parent}'."
                    );
                }
            }
        }

        $this->ensureAcyclic();
    }

    public function leaf_nodes(): array
    {
        $leaves = [];
        foreach (array_keys($this->nodes) as $name) {
            if (empty($this->children[$name])) {
                $leaves[] = $name;
            }
        }
        return $leaves;
    }

    public function root_nodes(?string $appName = null): array
    {
        $roots = [];

        foreach (array_keys($this->nodes) as $name) {
            if ($appName !== null && !str_starts_with($name, $appName . '.')) {
                continue;
            }

            $parents = $this->dependencies[$name] ?? [];
            if ($appName !== null) {
                $parents = array_filter(
                    $parents,
                    static fn(string $parent): bool => str_starts_with($parent, $appName . '.')
                );
            }

            if ($parents === []) {
                $roots[] = $name;
            }
        }

        sort($roots);

        return $roots;
    }

    /**
     * Finds all terminal nodes (heads) for a specific app.
     * If more than one head exists, there is a migration conflict.
     *
     * @return string[]
     */
    public function find_heads(string $appName): array
    {
        $heads = [];
        foreach (array_keys($this->nodes) as $name) {
            if (!str_starts_with($name, $appName . '.')) {
                continue;
            }

            $hasChildInApp = false;
            if (isset($this->children[$name])) {
                foreach ($this->children[$name] as $child) {
                    if (str_starts_with($child, $appName . '.')) {
                        $hasChildInApp = true;
                        break;
                    }
                }
            }

            if (!$hasChildInApp) {
                $heads[] = $name;
            }
        }

        sort($heads);

        return $heads;
    }

    /**
     * @param string|string[] $targets
     * @return string[]
     */
    public function forwards_plan($targets): array
    {
        $this->validate();
        $targets = (array) $targets;
        $plan = [];
        $visited = [];

        foreach ($targets as $target) {
            $this->dfsDependencies($target, $visited, $plan);
        }

        return $plan;
    }

    /**
     * @param string|string[] $targets
     * @return string[]
     */
    public function backwards_plan($targets): array
    {
        $this->validate();
        $targets = (array) $targets;
        $plan = [];
        $visited = [];

        foreach ($targets as $target) {
            $this->dfsChildren($target, $visited, $plan);
        }

        return $plan;
    }

    private function ensureAcyclic(): void
    {
        $visiting = [];
        $visited = [];

        foreach (array_keys($this->nodes) as $nodeName) {
            $this->detectCycle($nodeName, $visiting, $visited, []);
        }
    }

    private function detectCycle(string $nodeName, array &$visiting, array &$visited, array $path): void
    {
        if (isset($visited[$nodeName])) {
            return;
        }

        if (isset($visiting[$nodeName])) {
            $path[] = $nodeName;
            throw new \Nudelsalat\Migrations\Exceptions\CircularDependencyError(
                'Circular migration dependency detected: ' . implode(' -> ', $path)
            );
        }

        $visiting[$nodeName] = true;
        $path[] = $nodeName;

        foreach ($this->dependencies[$nodeName] ?? [] as $parentName) {
            $this->detectCycle($parentName, $visiting, $visited, $path);
        }

        unset($visiting[$nodeName]);
        $visited[$nodeName] = true;
    }

    private function dfsDependencies(string $nodeName, array &$visited, array &$plan): void
    {
        if (isset($visited[$nodeName])) {
            return;
        }

        $visited[$nodeName] = true;

        foreach ($this->dependencies[$nodeName] ?? [] as $parentName) {
            $this->dfsDependencies($parentName, $visited, $plan);
        }

        if (isset($this->nodes[$nodeName])) {
            $plan[] = $nodeName;
        }
    }

    private function dfsChildren(string $nodeName, array &$visited, array &$plan): void
    {
        if (isset($visited[$nodeName])) {
            return;
        }

        $visited[$nodeName] = true;

        foreach ($this->children[$nodeName] ?? [] as $childName) {
            $this->dfsChildren($childName, $visited, $plan);
        }

        if (isset($this->nodes[$nodeName])) {
            $plan[] = $nodeName;
        }
    }
}
