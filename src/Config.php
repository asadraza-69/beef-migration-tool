<?php

namespace Nudelsalat;

class Config
{
    private array $data;
    private string $baseDir;

    public function __construct(array $data, string $baseDir = null)
    {
        $this->data = $data;
        $this->baseDir = $baseDir ?? getcwd();
    }

    public static function load(string $path): self
    {
        if (!file_exists($path)) {
            throw new \Exception("Configuration file not found at: {$path}");
        }

        $data = require $path;
        if (!is_array($data)) {
            throw new \Exception("Configuration file must return an array.");
        }

        // Determine base directory from config file location
        $baseDir = dirname($path);

        return new self($data, $baseDir);
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $current = $this->data;

        foreach ($parts as $part) {
            if (!isset($current[$part])) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }

    public function getApps(): array
    {
        $apps = $this->get('apps', []);
        
        // Resolve paths relative to config file's directory
        foreach ($apps as $name => $config) {
            if (isset($config['models'])) {
                $apps[$name]['models'] = $this->resolvePath($config['models']);
            }
            if (isset($config['migrations'])) {
                $apps[$name]['migrations'] = $this->resolvePath($config['migrations']);
            }
            if (isset($config['seeders'])) {
                $apps[$name]['seeders'] = $this->resolvePath($config['seeders']);
            }
            if (isset($config['fixtures'])) {
                $apps[$name]['fixtures'] = $this->resolvePath($config['fixtures']);
            }
        }
        
        return $apps;
    }

    public function getSeedersDir(): ?string
    {
        return $this->get('seeders_dir') ? $this->resolvePath($this->get('seeders_dir')) : null;
    }

    public function getFixturesDir(): ?string
    {
        return $this->get('fixtures_dir') ? $this->resolvePath($this->get('fixtures_dir')) : null;
    }

    private function resolvePath(string $path): string
    {
        // If path is absolute, return as-is
        if (str_starts_with($path, '/')) {
            return $path;
        }
        
        // Resolve relative to config file's directory
        return $this->baseDir . '/' . ltrim($path, '/');
    }

    public function getDatabases(): array
    {
        $databases = $this->get('databases');
        if (is_array($databases) && $databases !== []) {
            return $databases;
        }

        return [
            $this->getDefaultDatabaseAlias() => $this->get('database', [
                'dsn' => 'sqlite::memory:',
                'user' => 'root',
                'pass' => '',
            ]),
        ];
    }

    public function getDefaultDatabaseAlias(): string
    {
        return (string) $this->get('default_database', 'default');
    }

    public function getDatabaseConfig(?string $alias = null): array
    {
        $alias = $alias ?: $this->getDefaultDatabaseAlias();
        $databases = $this->getDatabases();

        if (!isset($databases[$alias])) {
            throw new \InvalidArgumentException("Unknown database alias '{$alias}'.");
        }

        return $databases[$alias];
    }

    public function getMigrationRouterDefinition(): mixed
    {
        return $this->get('migration_router');
    }
}
