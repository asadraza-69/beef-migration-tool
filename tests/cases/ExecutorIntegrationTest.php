<?php

namespace Nudelsalat\Tests\Cases;

use Nudelsalat\Tests\Support\TestProject;
use Nudelsalat\Tests\TestCase;

class ExecutorIntegrationTest extends TestCase
{
    public function testExecutorAppliesMigrationsAgainstSqlite(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeMigrationChain($project);
        $databasePath = $project->root . '/test.sqlite';

        $executor = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath])->createExecutor();
        $executor->migrate();

        $pdo = new \PDO('sqlite:' . $databasePath);
        $columns = $pdo->query('PRAGMA table_info("users")')->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_map(static fn(array $row): string => $row['name'], $columns);

        $this->assertTrue(in_array('email', $columnNames, true), 'Expected email column after migrations.');
    }

    public function testFakeInitialRecognizesExistingTableAndColumns(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Schema\Column;
return new class('0001_initial') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->operations = [
            new CreateModel('users', [
                new Column('id', 'int', false, null, true, true),
                new Column('name', 'string'),
            ]),
            new AddField('users', 'email', new Column('email', 'string', true)),
        ];
    }
};
PHP);
        $databasePath = $project->root . '/fake_initial.sqlite';
        $pdo = new \PDO('sqlite:' . $databasePath);
        $pdo->exec('CREATE TABLE "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR, "email" VARCHAR NULL)');

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $bootstrap->createExecutor()->migrate(null, false, true);

        $applied = $bootstrap->createRecorder()->getAppliedMigrations();
        $this->assertTrue(in_array('main.0001_initial', $applied, true));
    }

    public function testFakeInitialRecognizesSeparateDatabaseAndStateOperations(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\SeparateDatabaseAndState;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Schema\Column;
return new class('0001_initial') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->operations = [
            new SeparateDatabaseAndState([
                new CreateModel('users', [
                    new Column('id', 'int', false, null, true, true),
                    new Column('name', 'string'),
                ]),
                new AddField('users', 'email', new Column('email', 'string', true)),
            ])
        ];
    }
};
PHP);
        $databasePath = $project->root . '/fake_initial_separate.sqlite';
        $pdo = new \PDO('sqlite:' . $databasePath);
        $pdo->exec('CREATE TABLE "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR, "email" VARCHAR NULL)');

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $bootstrap->createExecutor()->migrate(null, false, true);

        $applied = $bootstrap->createRecorder()->getAppliedMigrations();
        $this->assertTrue(in_array('main.0001_initial', $applied, true));
    }

    public function testReplacementMigrationIsNotAutoRecordedWhenOriginalsAreApplied(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeMigrationChain($project, true);
        $databasePath = $project->root . '/replacement.sqlite';

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $recorder = $bootstrap->createRecorder();
        $recorder->ensureSchema();
        $recorder->recordApplied('main.0001_initial', 'checksum-1');
        $recorder->recordApplied('main.0002_add_email', 'checksum-2');

        $bootstrap->createExecutor()->migrate();

        $applied = $recorder->getAppliedMigrations();
        $this->assertFalse(in_array('main.0003_squashed', $applied, true));
    }

    public function testReplacementRecordIsPrunedWhenOriginalsAreUnapplied(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeMigrationChain($project, true);
        $databasePath = $project->root . '/replacement_prune.sqlite';

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $recorder = $bootstrap->createRecorder();
        $recorder->ensureSchema();
        $recorder->recordApplied('main.0001_initial', 'checksum-1');
        $recorder->recordApplied('main.0002_add_email', 'checksum-2');
        $recorder->recordApplied('main.0003_squashed', 'checksum-3');

        $pdo = $bootstrap->getPdo();
        $pdo->exec('CREATE TABLE "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR, "email" VARCHAR NULL)');

        $bootstrap->createExecutor()->unmigrate('main.0002_add_email');

        $applied = $recorder->getAppliedMigrations();
        $this->assertFalse(in_array('main.0002_add_email', $applied, true));
        $this->assertFalse(in_array('main.0003_squashed', $applied, true));
    }

    public function testExecutorPrefersReplacementWhenNothingFromOriginalChainIsApplied(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeMigrationChain($project, true);
        $databasePath = $project->root . '/prefer_replacement.sqlite';

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $bootstrap->createExecutor()->migrate();

        $applied = $bootstrap->createRecorder()->getAppliedMigrations();
        $this->assertTrue(in_array('main.0003_squashed', $applied, true));
        $this->assertFalse(in_array('main.0001_initial', $applied, true));
        $this->assertFalse(in_array('main.0002_add_email', $applied, true));
    }

    public function testExecutorKeepsOriginalChainDuringPartialReplacementApplication(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeMigrationChain($project, true);
        $databasePath = $project->root . '/partial_replacement.sqlite';

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $recorder = $bootstrap->createRecorder();
        $recorder->ensureSchema();

        $pdo = $bootstrap->getPdo();
        $pdo->exec('CREATE TABLE "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR)');
        $recorder->recordApplied('main.0001_initial', 'checksum-1');

        $bootstrap->createExecutor()->migrate();

        $applied = $recorder->getAppliedMigrations();
        $this->assertTrue(in_array('main.0002_add_email', $applied, true));
        $this->assertFalse(in_array('main.0003_squashed', $applied, true));
    }

    public function testExecutorResolvesReplacementTargetToOriginalChainDuringPartialHistory(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeMigrationChain($project, true);
        $databasePath = $project->root . '/replacement_target_resolution.sqlite';

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $recorder = $bootstrap->createRecorder();
        $recorder->ensureSchema();

        $pdo = $bootstrap->getPdo();
        $pdo->exec('CREATE TABLE "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR)');
        $recorder->recordApplied('main.0001_initial', 'checksum-1');

        $bootstrap->createExecutor()->migrate(['main.0003_squashed']);

        $applied = $recorder->getAppliedMigrations();
        $this->assertTrue(in_array('main.0002_add_email', $applied, true));
        $this->assertFalse(in_array('main.0003_squashed', $applied, true));
    }

    public function testUnmigrateUsingReplacementAliasUnappliesOriginalChain(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeMigrationChain($project, true);
        $databasePath = $project->root . '/replacement_unmigrate.sqlite';

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $bootstrap->createExecutor()->migrate();

        $executor = $bootstrap->createExecutor();
        $executor->unmigrate('main.0003_squashed');

        $applied = $bootstrap->createRecorder()->getAppliedMigrations();
        $this->assertFalse(in_array('main.0001_initial', $applied, true));
        $this->assertFalse(in_array('main.0002_add_email', $applied, true));
        $this->assertFalse(in_array('main.0003_squashed', $applied, true));

        $pdo = $bootstrap->getPdo();
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->execute(['name' => 'users']);
        $this->assertFalse((bool) $stmt->fetchColumn(), 'Expected users table to be removed after alias unmigrate.');
    }

    public function testUnmigratingRedundantReplacementRecordKeepsOriginalSchema(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeMigrationChain($project, true);
        $databasePath = $project->root . '/redundant_replacement_unmigrate.sqlite';

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $recorder = $bootstrap->createRecorder();
        $recorder->ensureSchema();
        $recorder->recordApplied('main.0001_initial', 'checksum-1');
        $recorder->recordApplied('main.0002_add_email', 'checksum-2');
        $recorder->recordApplied('main.0003_squashed', 'checksum-3');

        $pdo = $bootstrap->getPdo();
        $pdo->exec('CREATE TABLE "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR, "email" VARCHAR NULL)');

        $bootstrap->createExecutor()->unmigrate('main.0003_squashed');

        $applied = $recorder->getAppliedMigrations();
        $this->assertTrue(in_array('main.0001_initial', $applied, true));
        $this->assertTrue(in_array('main.0002_add_email', $applied, true));
        $this->assertFalse(in_array('main.0003_squashed', $applied, true));

        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->execute(['name' => 'users']);
        $this->assertTrue((bool) $stmt->fetchColumn(), 'Expected users table to remain when only a redundant replacement record is removed.');
    }

    public function testAtomicMigrationRollsBackOnFailure(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeFailingMigration($project, '0001_atomic_fail', true);
        $databasePath = $project->root . '/atomic_fail.sqlite';

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $this->assertThrows(\PDOException::class, fn() => $bootstrap->createExecutor()->migrate());

        $pdo = $bootstrap->getPdo();
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->execute(['name' => 'broken_atomic']);
        $this->assertFalse((bool) $stmt->fetchColumn(), 'Expected atomic migration to roll back table creation.');
        $this->assertSame([], $bootstrap->createRecorder()->getAppliedMigrations());
    }

    public function testNonAtomicMigrationLeavesSideEffectsOnFailure(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeFailingMigration($project, '0001_non_atomic_fail', false);
        $databasePath = $project->root . '/non_atomic_fail.sqlite';

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $this->assertThrows(\PDOException::class, fn() => $bootstrap->createExecutor()->migrate());

        $pdo = $bootstrap->getPdo();
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->execute(['name' => 'broken_non_atomic']);
        $this->assertTrue((bool) $stmt->fetchColumn(), 'Expected non-atomic migration to leave created table behind on failure.');
        $this->assertSame([], $bootstrap->createRecorder()->getAppliedMigrations());
    }

    public function testExecutorRejectsMixedTargetPlan(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeMigrationChain($project);
        $databasePath = $project->root . '/mixed_plan.sqlite';

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $bootstrap->createExecutor()->migrate(['main.0001_initial']);

        $this->assertThrows(
            \Nudelsalat\Migrations\Exceptions\InvalidMigrationPlan::class,
            fn() => $bootstrap->createExecutor()->migrate(['main.0001_initial', 'main.0002_add_email']),
            'Mixed migration plan detected'
        );
    }

    public function testPostgresqlRecorderAndMigrationFlowWhenConfigured(): void
    {
        $dsn = getenv('BEEF_TEST_PGSQL_DSN') ?: '';
        if ($dsn === '') {
            $this->markSkipped('BEEF_TEST_PGSQL_DSN not configured.');
        }

        $project = new TestProject($this->createTemporaryDirectory());
        $this->writeMigrationChain($project);
        $bootstrap = $project->createBootstrap([
            'dsn' => $dsn,
            'user' => getenv('BEEF_TEST_PGSQL_USER') ?: 'beef',
            'pass' => getenv('BEEF_TEST_PGSQL_PASS') ?: 'beef',
        ]);

        $pdo = $bootstrap->getPdo();
        $pdo->exec('DROP TABLE IF EXISTS "users" CASCADE');
        $pdo->exec('DROP TABLE IF EXISTS "beef_migrations" CASCADE');

        $bootstrap->createExecutor()->migrate();

        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'users'");
        $columnNames = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertTrue(in_array('email', $columnNames, true));
    }

    private function writeMigrationChain(TestProject $project, bool $includeReplacement = false): void
    {
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
                new Column('name', 'string'),
            ]),
        ];
    }
};
PHP);
        $project->writeMigration('0002_add_email', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Schema\Column;
return new class('0002_add_email') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->dependencies = ['0001_initial'];
        $this->operations = [
            new AddField('users', 'email', new Column('email', 'string', true)),
        ];
    }
};
PHP);

        if ($includeReplacement) {
            $project->writeMigration('0003_squashed', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Schema\Column;
return new class('0003_squashed') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->dependencies = [];
        $this->replaces = ['0001_initial', '0002_add_email'];
        $this->operations = [
            new CreateModel('users', [
                new Column('id', 'int', false, null, true, true),
                new Column('name', 'string'),
            ]),
            new AddField('users', 'email', new Column('email', 'string', true)),
        ];
    }
};
PHP);
        }
    }

    private function writeFailingMigration(TestProject $project, string $name, bool $atomic): void
    {
        $atomicLiteral = $atomic ? 'true' : 'false';
        $tableName = $atomic ? 'broken_atomic' : 'broken_non_atomic';

        $project->writeMigration($name, <<<PHP
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\RunSQL;
use Nudelsalat\Schema\Column;
return new class('{$name}') extends Migration {
    public bool \$atomic = {$atomicLiteral};
    public function __construct(string \$name)
    {
        parent::__construct(\$name);
        \$this->operations = [
            new CreateModel('{$tableName}', [
                new Column('id', 'int', false, null, true, true),
            ]),
            new RunSQL('THIS IS NOT VALID SQL'),
        ];
    }
};
PHP);
    }
}
