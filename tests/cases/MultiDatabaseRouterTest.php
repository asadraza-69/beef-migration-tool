<?php

namespace Nudelsalat\Tests\Cases;

use Nudelsalat\Bootstrap;
use Nudelsalat\Config;
use Nudelsalat\Console\Commands\MigrateCommand;
use Nudelsalat\Tests\Support\TestProject;
use Nudelsalat\Tests\TestCase;

class MultiDatabaseRouterTest extends TestCase
{
    public function testRouterCanSkipSchemaWorkOnSpecificDatabase(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Schema\Column;
return new class('0001_initial') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->operations = [
            new CreateModel('users', [
                new Column('id', 'int', false, null, true, true),
            ]),
        ];
    }
};
PHP);

        $bootstrap = new Bootstrap(new Config([
            'databases' => [
                'default' => ['dsn' => 'sqlite:' . $project->root . '/default.sqlite'],
                'replica' => ['dsn' => 'sqlite:' . $project->root . '/replica.sqlite'],
            ],
            'apps' => [
                'main' => [
                    'models' => $project->modelsDir,
                    'migrations' => $project->migrationsDir,
                ],
            ],
            'migration_router' => static fn(string $databaseAlias, string $appLabel): bool => $databaseAlias !== 'replica',
        ]));

        $this->captureOutput(fn() => (new MigrateCommand($bootstrap))->execute(['--database=replica']));
        $replicaApplied = $bootstrap->createRecorder('replica')->getAppliedMigrations();
        $this->assertTrue(in_array('main.0001_initial', $replicaApplied, true));

        $replicaPdo = $bootstrap->getPdo('replica');
        $stmt = $replicaPdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
        $stmt->execute(['name' => 'users']);
        $this->assertFalse((bool) $stmt->fetchColumn(), 'Replica database should not have users table when router blocks migration.');

        $this->captureOutput(fn() => (new MigrateCommand($bootstrap))->execute(['--database=default']));
        $defaultPdo = $bootstrap->getPdo('default');
        $stmt = $defaultPdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
        $stmt->execute(['name' => 'users']);
        $this->assertTrue((bool) $stmt->fetchColumn(), 'Default database should receive schema changes.');
    }
}
