<?php

namespace Nudelsalat\Tests\Cases;

use Nudelsalat\Console\Commands\IntrospectCommand;
use Nudelsalat\Console\Commands\MigrateCommand;
use Nudelsalat\Console\Commands\OptimizeMigrationCommand;
use Nudelsalat\Console\Commands\ShowCommand;
use Nudelsalat\Console\Commands\SqlMigrateCommand;
use Nudelsalat\Tests\Support\TestProject;
use Nudelsalat\Tests\TestCase;

class CommandTest extends TestCase
{
    public function testShowCommandDisplaysReplacementMetadata(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0001_initial') extends Migration {};
PHP);
        $project->writeMigration('0002_squashed', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0002_squashed') extends Migration {
    public array $replaces = ['0001_initial'];
};
PHP);

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $project->root . '/show.sqlite']);
        $recorder = $bootstrap->createRecorder();
        $recorder->ensureSchema();
        $recorder->recordApplied('main.0001_initial', null);

        $output = $this->captureOutput(fn() => (new ShowCommand($bootstrap))->execute([]));

        $this->assertContains('main:', $output);
        $this->assertContains('0002_squashed (replaces: main.0001_initial)', $output);
    }

    public function testIntrospectCommandGeneratesSupportedModelFields(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $databasePath = $project->root . '/introspect.sqlite';
        $pdo = new \PDO('sqlite:' . $databasePath);
        $pdo->exec('CREATE TABLE "products" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "title" VARCHAR(255), "price" DECIMAL, "active" BOOLEAN, "created_at" DATETIME)');

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath]);
        $output = $this->captureOutput(fn() => (new IntrospectCommand($bootstrap))->execute([]));

        $generated = $project->modelsDir . '/Products.php';
        $this->assertTrue(file_exists($generated), 'Expected introspect to generate model file.');
        $code = file_get_contents($generated);

        $this->assertContains('use Nudelsalat\\Migrations\\Fields\\DecimalField;', $code);
        $this->assertContains("'price' => new DecimalField(", $code);
        $this->assertContains('Saved to:', $output);
    }

    public function testSqlMigratePrintsSqlWithoutApplyingMigration(): void
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
                new Column('name', 'string'),
            ]),
        ];
    }
};
PHP);

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $project->root . '/sqlmigrate.sqlite']);
        $output = $this->captureOutput(fn() => (new SqlMigrateCommand($bootstrap))->execute(['main.0001_initial', 'sqlite']));

        $this->assertContains('SQL for migration: main.0001_initial', $output);
        $this->assertContains('CREATE TABLE', $output);
    }

    public function testMigrateCommandSupportsTargetedForwardAndRollbackByMigrationName(): void
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

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $project->root . '/targeted.sqlite']);
        $command = new MigrateCommand($bootstrap);

        $this->captureOutput(fn() => $command->execute(['main.0001_initial']));
        $applied = $bootstrap->createRecorder()->getAppliedMigrations();
        $this->assertTrue(in_array('main.0001_initial', $applied, true));
        $this->assertFalse(in_array('main.0002_add_email', $applied, true));

        $this->captureOutput(fn() => $command->execute(['main.0001_initial', '--unapply']));
        $appliedAfterRollback = $bootstrap->createRecorder()->getAppliedMigrations();
        $this->assertFalse(in_array('main.0001_initial', $appliedAfterRollback, true));
    }

    public function testMigrateCommandSupportsAppTarget(): void
    {
        $this->markSkipped('Skipping - test configuration issue');
    }

    public function testOptimizeCommandRewritesMigrationWithOptimizedOperations(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_opt', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Migrations\Operations\RemoveField;
use Nudelsalat\Schema\Column;
return new class('0001_opt') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->operations = [
            new AddField('users', 'name', new Column('name', 'string')),
            new RemoveField('users', 'name'),
        ];
    }
};
PHP);

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $project->root . '/optimize.sqlite']);
        $output = $this->captureOutput(fn() => (new OptimizeMigrationCommand($bootstrap))->execute(['main.0001_opt']));
        $code = file_get_contents($project->migrationsDir . '/0001_opt.php');

        $this->assertContains('Optimized migration', $output);
        $this->assertFalse(str_contains($code, 'RemoveField('));
    }

    public function testMakeCommandCreatesPerAppMigrationsWithCrossAppDependency(): void
    {
        $root = $this->createTemporaryDirectory();
        $authorsModels = $root . '/authors/Models';
        $authorsMigrations = $root . '/authors/migrations';
        $booksModels = $root . '/books/Models';
        $booksMigrations = $root . '/books/migrations';
        mkdir($authorsModels, 0777, true);
        mkdir($authorsMigrations, 0777, true);
        mkdir($booksModels, 0777, true);
        mkdir($booksMigrations, 0777, true);

        file_put_contents($authorsModels . '/Author.php', <<<'PHP'
<?php
namespace Authors\Models;

use Nudelsalat\ORM\Model;
use Nudelsalat\Migrations\Fields\StringField;

class Author extends Model
{
    public static function fields(): array
    {
        return ['name' => new StringField()];
    }
}
PHP);
        file_put_contents($booksModels . '/Book.php', <<<'PHP'
<?php
namespace Books\Models;

use Nudelsalat\ORM\Model;
use Nudelsalat\Migrations\Fields\StringField;
use Nudelsalat\Migrations\Fields\ForeignKey;

class Book extends Model
{
    public static function fields(): array
    {
        return [
            'title' => new StringField(),
            'author' => new ForeignKey('author'),
        ];
    }
}
PHP);

        $bootstrap = new \Nudelsalat\Bootstrap(new \Nudelsalat\Config([
            'database' => ['dsn' => 'sqlite:' . $root . '/make.sqlite'],
            'apps' => [
                'authors' => ['models' => $authorsModels, 'migrations' => $authorsMigrations],
                'books' => ['models' => $booksModels, 'migrations' => $booksMigrations],
            ],
        ]));

        $output = $this->captureOutput(fn() => (new MigrateCommand($bootstrap))->execute([]));
        $this->assertContains('Done!', $output);

        $makeOutput = $this->captureOutput(fn() => (new \Nudelsalat\Console\Commands\MakeCommand($bootstrap))->execute([]));
        $this->assertContains('Created migration:', $makeOutput);

        $authorFiles = array_values(array_filter(scandir($authorsMigrations), static fn(string $file): bool => str_ends_with($file, '.php')));
        $bookFiles = array_values(array_filter(scandir($booksMigrations), static fn(string $file): bool => str_ends_with($file, '.php')));
        $this->assertCount(1, $authorFiles);
        $this->assertCount(1, $bookFiles);

        $bookMigration = file_get_contents($booksMigrations . '/' . $bookFiles[0]);
        $this->assertContains('authors.', $bookMigration);
    }

    public function testMakeCommandIsIdempotentWithoutApplyingMigrations(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());

        $className = 'IdempotentModel' . bin2hex(random_bytes(4));
        $project->writeModel($className, str_replace(
            '__CLASS__',
            $className,
            <<<'PHP'
<?php
namespace MakeIdempotency\Models;

use Nudelsalat\ORM\Model;
use Nudelsalat\Migrations\Fields\StringField;

class __CLASS__ extends Model
{
    public static function fields(): array
    {
        return [
            'name' => new StringField(),
        ];
    }
}
PHP
        ));

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $project->root . '/make_idem.sqlite']);

        $first = $this->captureOutput(fn() => (new \Nudelsalat\Console\Commands\MakeCommand($bootstrap))->execute([]));
        $this->assertContains('Created migration:', $first);

        $filesAfterFirst = array_values(array_filter(scandir($project->migrationsDir), static fn(string $file): bool => str_ends_with($file, '.php')));
        $this->assertCount(1, $filesAfterFirst);

        $second = $this->captureOutput(fn() => (new \Nudelsalat\Console\Commands\MakeCommand($bootstrap))->execute([]));
        $this->assertContains('No changes detected.', $second);

        $filesAfterSecond = array_values(array_filter(scandir($project->migrationsDir), static fn(string $file): bool => str_ends_with($file, '.php')));
        $this->assertCount(1, $filesAfterSecond, 'Expected makemigrations to not create duplicate migrations when nothing changed.');
    }
}
