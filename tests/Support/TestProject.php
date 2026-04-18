<?php

namespace Nudelsalat\Tests\Support;

class TestProject
{
    public string $root;
    public string $modelsDir;
    public string $migrationsDir;

    public function __construct(string $root)
    {
        $this->root = $root;
        $this->modelsDir = $root . '/app/Models';
        $this->migrationsDir = $root . '/app/migrations';

        mkdir($this->modelsDir, 0777, true);
        mkdir($this->migrationsDir, 0777, true);
    }

    public function writeModel(string $className, string $code): string
    {
        $path = $this->modelsDir . '/' . $className . '.php';
        file_put_contents($path, $code);
        return $path;
    }

    public function writeMigration(string $fileName, string $code): string
    {
        $path = $this->migrationsDir . '/' . $fileName . '.php';
        file_put_contents($path, $code);
        return $path;
    }

    public function createBootstrap(array $databaseConfig): \Nudelsalat\Bootstrap
    {
        return new \Nudelsalat\Bootstrap(new \Nudelsalat\Config([
            'database' => $databaseConfig,
            'apps' => [
                'main' => [
                    'models' => $this->modelsDir,
                    'migrations' => $this->migrationsDir,
                ],
            ],
        ]));
    }

    public function createExecutor(array $databaseConfig = []): \Nudelsalat\Migrations\Executor
    {
        $config = $databaseConfig ?: ['dsn' => 'sqlite::memory:'];
        return $this->createBootstrap($config)->createExecutor();
    }

    public function getPdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }
}
