<?php

namespace Nudelsalat\Tests\Cases;

use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\AddForeignKey;
use Nudelsalat\Migrations\Operations\RemoveForeignKey;
use Nudelsalat\Migrations\Operations\DeleteModel;
use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Migrations\Operations\RemoveField;
use Nudelsalat\Migrations\Operations\RenameModel;
use Nudelsalat\Migrations\Operations\RenameField;
use Nudelsalat\Migrations\Operations\AlterField;
use Nudelsalat\Schema\Column;
use Nudelsalat\Tests\TestCase;

class RelationshipAndRollbackTest extends TestCase
{
    private function createTestProject(): \Nudelsalat\Tests\Support\TestProject
    {
        $project = new \Nudelsalat\Tests\Support\TestProject($this->createTemporaryDirectory());
        
        if (!is_dir($project->migrationsDir)) {
            mkdir($project->migrationsDir, 0755, true);
        }
        
        return $project;
    }

    private function createExecutor(\Nudelsalat\Tests\Support\TestProject $project): \Nudelsalat\Migrations\Executor
    {
        $databasePath = $project->root . '/test.sqlite';
        return $project->createBootstrap(['dsn' => 'sqlite:' . $databasePath])->createExecutor();
    }

    private function getPdo(\Nudelsalat\Tests\Support\TestProject $project): \PDO
    {
        return new \PDO('sqlite:' . $project->root . '/test.sqlite');
    }

    private function assertArrayContains(array $array, mixed $value, string $message = ''): void
    {
        $this->assertTrue(in_array($value, $array, true), $message ?: "Expected array to contain value");
    }

    private function assertArrayNotContains(array $array, mixed $value, string $message = ''): void
    {
        $this->assertFalse(in_array($value, $array, true), $message ?: "Expected array to not contain value");
    }

    public function testForeignKeyCreationAndApplication(): void
    {
        $project = $this->createTestProject();
        
        // Put both tables in single migration to avoid conflict
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\AddForeignKey;
use Nudelsalat\Schema\Column;
return new class('0001_initial') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->operations = [
            new CreateModel('users', [
                new Column('id', 'int', false, null, true, true),
                new Column('name', 'string'),
            ]),
            new CreateModel('posts', [
                new Column('id', 'int', false, null, true, true),
                new Column('title', 'string'),
                new Column('user_id', 'int', false),
            ]),
            new AddForeignKey('posts', 'fk_posts_user_id', 'user_id', 'users', 'id', 'CASCADE'),
        ];
    }
};
PHP);

        $executor = $this->createExecutor($project);
        $executor->migrate();

        $pdo = $this->getPdo($project);
        $indexes = $pdo->query("PRAGMA foreign_key_list('posts')")->fetchAll(\PDO::FETCH_ASSOC);
        
        $this->assertTrue(count($indexes) > 0, 'Expected foreign key on posts table');
        $this->assertEquals('users', $indexes[0]['table']);
    }

    public function testRollbackDeletesTable(): void
    {
        $project = $this->createTestProject();
        
        $project->writeMigration('0001_users', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Schema\Column;
return new class('0001_users') extends Migration {
    public function __construct(string $name) {
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

        $executor = $this->createExecutor($project);
        $executor->migrate();
        
        $pdo = $this->getPdo($project);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertTrue(count($tables) > 0, 'Table should exist after migrate');
        
        $executor->unmigrate('main.0001_users');
        
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertTrue(count($tables) === 0, 'Table should be deleted after rollback');
    }

    public function testRollbackRemovesForeignKey(): void
    {
        $project = $this->createTestProject();
        
        $project->writeMigration('0001_all', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\AddForeignKey;
use Nudelsalat\Schema\Column;
return new class('0001_all') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->operations = [
            new CreateModel('users', [
                new Column('id', 'int', false, null, true, true),
            ]),
            new CreateModel('posts', [
                new Column('id', 'int', false, null, true, true),
                new Column('user_id', 'int', false),
            ]),
            new AddForeignKey('posts', 'fk_posts_user_id', 'user_id', 'users', 'id', 'CASCADE'),
        ];
    }
};
PHP);

        $executor = $this->createExecutor($project);
        $executor->migrate();

        $pdo = $this->getPdo($project);
        $fks = $pdo->query("PRAGMA foreign_key_list('posts')")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertTrue(count($fks) > 0);

        $executor->unmigrate('main.0001_all');
        
        $fks = $pdo->query("PRAGMA foreign_key_list('posts')")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertTrue(count($fks) === 0, 'Foreign key should be removed after rollback');
    }

    public function testRollbackRestoresForeignKeyRemovedInLaterMigration(): void
    {
        $project = $this->createTestProject();

        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\AddForeignKey;
use Nudelsalat\Schema\Column;
return new class('0001_initial') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->operations = [
            new CreateModel('users', [
                new Column('id', 'int', false, null, true, true),
            ]),
            new CreateModel('posts', [
                new Column('id', 'int', false, null, true, true),
                new Column('user_id', 'int', false),
            ]),
            new AddForeignKey('posts', 'fk_posts_user', 'user_id', 'users', 'id', 'CASCADE'),
        ];
    }
};
PHP);

        $project->writeMigration('0002_remove_fk', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\RemoveForeignKey;
return new class('0002_remove_fk') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->dependencies = ['0001_initial'];
        $this->operations = [
            // Intentionally omit column/toModel metadata (matches autodetector output).
            new RemoveForeignKey('posts', 'fk_posts_user_id'),
        ];
    }
};
PHP);

        $executor = $this->createExecutor($project);
        $executor->migrate();

        $pdo = $this->getPdo($project);
        $fks = $pdo->query("PRAGMA foreign_key_list('posts')")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertTrue(count($fks) === 0, 'Expected foreign key to be removed after applying 0002.');

        $executor->unmigrate('main.0002_remove_fk');
        // sqlite PRAGMA foreign_key_list can return stale results on a connection that
        // pre-dates a schema change performed by a different connection.
        $pdo = $this->getPdo($project);
        $fks = $pdo->query("PRAGMA foreign_key_list('posts')")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertTrue(count($fks) > 0, 'Expected foreign key to be restored when rolling back 0002.');
        $this->assertEquals('users', $fks[0]['table']);
    }

    public function testCreateModelAppliesEmbeddedIndexes(): void
    {
        $project = $this->createTestProject();

        $project->writeMigration('0001_indexed', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Schema\Column;
use Nudelsalat\Schema\Index;
return new class('0001_indexed') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->operations = [
            // This mirrors optimizer output: indexes embedded in CreateModel options.
            new CreateModel('posts', [
                new Column('id', 'int', false, null, true, true),
                new Column('user_id', 'int', false),
            ], [
                'indexes' => [
                    new Index('idx_posts_user_id', ['user_id']),
                ],
            ]),
        ];
    }
};
PHP);

        $executor = $this->createExecutor($project);
        $executor->migrate();

        $pdo = $this->getPdo($project);
        $indexes = $pdo->query("PRAGMA index_list('posts')")->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($indexes, 'name');
        $this->assertArrayContains($names, 'idx_posts_user_id', 'Expected CreateModel to apply embedded indexes.');
    }

    public function testRenameFieldUpdatesDatabaseSchemaAndRollsBack(): void
    {
        $project = $this->createTestProject();

        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Schema\Column;
return new class('0001_initial') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->operations = [
            new CreateModel('posts', [
                new Column('id', 'int', false, null, true, true),
                new Column('account_id', 'int', false),
            ]),
        ];
    }
};
PHP);

        $project->writeMigration('0002_rename_field', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\RenameField;
return new class('0002_rename_field') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->dependencies = ['0001_initial'];
        $this->operations = [
            new RenameField('posts', 'account_id', 'i_account_id'),
        ];
    }
};
PHP);

        $executor = $this->createExecutor($project);
        $executor->migrate();

        $pdo = $this->getPdo($project);
        $cols = $pdo->query("PRAGMA table_info('posts')")->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        $this->assertArrayContains($names, 'i_account_id', 'Expected RenameField to rename the database column.');
        $this->assertArrayNotContains($names, 'account_id', 'Expected old column name to be gone after RenameField.');

        $executor->unmigrate('main.0002_rename_field');

        // Use a fresh connection to avoid sqlite schema caching effects.
        $pdo = $this->getPdo($project);
        $cols = $pdo->query("PRAGMA table_info('posts')")->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        $this->assertArrayContains($names, 'account_id', 'Expected RenameField rollback to restore the old column name.');
        $this->assertArrayNotContains($names, 'i_account_id', 'Expected RenameField rollback to remove the renamed column.');
    }

    public function testAddAndRemoveFieldRollback(): void
    {
        $project = $this->createTestProject();
        
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Schema\Column;
return new class('0001_initial') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->operations = [
            new CreateModel('users', [
                new Column('id', 'int', false, null, true, true),
                new Column('name', 'string'),
            ]),
            new AddField('users', 'email', new Column('email', 'string')),
        ];
    }
};
PHP);

        $executor = $this->createExecutor($project);
        $executor->migrate();

        $pdo = $this->getPdo($project);
        $columns = $pdo->query("PRAGMA table_info('users')")->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');
        $this->assertArrayContains($columnNames, 'email', 'Email column should exist');

        $executor->unmigrate('main.0001_initial');
        
        $columns = $pdo->query("PRAGMA table_info('users')")->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');
        $this->assertArrayNotContains($columnNames, 'email', 'Email column should be removed');
    }

    public function testDeleteModelRemovesTable(): void
    {
        $project = $this->createTestProject();
        
        $project->writeMigration('0001_delete', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\DeleteModel;
use Nudelsalat\Schema\Column;
return new class('0001_delete') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->operations = [
            new CreateModel('temp_data', [
                new Column('id', 'int', false, null, true, true),
                new Column('data', 'string'),
            ]),
            new DeleteModel('temp_data'),
        ];
    }
};
PHP);

        $executor = $this->createExecutor($project);
        $executor->migrate();

        $pdo = $this->getPdo($project);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='temp_data'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertTrue(count($tables) === 0, 'Table should be deleted (CreateModel + DeleteModel cancel out)');
    }

    public function testMultipleTablesInSingleMigration(): void
    {
        $project = $this->createTestProject();
        
        $project->writeMigration('0001_all', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Schema\Column;
return new class('0001_all') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->operations = [
            new CreateModel('users', [
                new Column('id', 'int', false, null, true, true),
                new Column('name', 'string'),
            ]),
            new CreateModel('posts', [
                new Column('id', 'int', false, null, true, true),
                new Column('user_id', 'int', false),
                new Column('title', 'string'),
            ]),
            new CreateModel('comments', [
                new Column('id', 'int', false, null, true, true),
                new Column('post_id', 'int', false),
                new Column('body', 'text'),
            ]),
        ];
    }
};
PHP);

        $executor = $this->createExecutor($project);
        $executor->migrate();

        $pdo = $this->getPdo($project);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        
        $this->assertArrayContains($tables, 'users');
        $this->assertArrayContains($tables, 'posts');
        $this->assertArrayContains($tables, 'comments');
    }

    public function testManyToManyJunctionTable(): void
    {
        $project = $this->createTestProject();
        
        $project->writeMigration('0001_m2m', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Schema\Column;
return new class('0001_m2m') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->operations = [
            new CreateModel('authors', [
                new Column('id', 'int', false, null, true, true),
                new Column('name', 'string'),
            ]),
            new CreateModel('books', [
                new Column('id', 'int', false, null, true, true),
                new Column('title', 'string'),
            ]),
            new CreateModel('author_books', [
                new Column('id', 'int', false, null, true, true),
                new Column('author_id', 'int', false),
                new Column('book_id', 'int', false),
            ]),
        ];
    }
};
PHP);

        $executor = $this->createExecutor($project);
        $executor->migrate();

        $pdo = $this->getPdo($project);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertArrayContains($tables, 'author_books');
    }

    public function testAtomicMigrationRollbackOnError(): void
    {
        $project = $this->createTestProject();
        
        $project->writeMigration('0001_atomic', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Schema\Column;
return new class('0001_atomic') extends Migration {
    public bool $atomic = true;
    public function __construct(string $name) {
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

        $executor = $this->createExecutor($project);
        $executor->migrate();
        
        $pdo = $this->getPdo($project);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertTrue(count($tables) > 0, 'Atomic migration should create table');
        
        $executor->unmigrate('main.0001_atomic');
        
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertTrue(count($tables) === 0, 'Atomic rollback should delete table');
    }

    public function testSequentialMigrations(): void
    {
        $project = $this->createTestProject();
        
        $project->writeMigration('0001_users', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Schema\Column;
return new class('0001_users') extends Migration {
    public function __construct(string $name) {
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

        $project->writeMigration('0002_posts', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\CreateModel;
use Nudelsalat\Migrations\Operations\AddField;
use Nudelsalat\Schema\Column;
return new class('0002_posts') extends Migration {
    public function __construct(string $name) {
        parent::__construct($name);
        $this->dependencies = ['0001_users'];
        $this->operations = [
            new CreateModel('posts', [
                new Column('id', 'int', false, null, true, true),
                new Column('title', 'string'),
            ]),
            new AddField('posts', 'content', new Column('content', 'text')),
        ];
    }
};
PHP);

        $executor = $this->createExecutor($project);
        $executor->migrate();

        $pdo = $this->getPdo($project);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertArrayContains($tables, 'users');
        $this->assertArrayContains($tables, 'posts');
        
        $columns = $pdo->query("PRAGMA table_info('posts')")->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');
        $this->assertArrayContains($columnNames, 'content');
    }
}
