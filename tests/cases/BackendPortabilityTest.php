<?php

namespace Nudelsalat\Tests\Cases;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Migrations\StateRegistry;
use Nudelsalat\Schema\Column;
use Nudelsalat\Schema\Introspector;
use Nudelsalat\Schema\Table;
use Nudelsalat\Tests\TestCase;

class BackendPortabilityTest extends TestCase
{
    public function testHistoricalModelUsesSqliteCompatibleQuoting(): void
    {
        $databasePath = $this->createTemporaryDirectory() . '/history.sqlite';
        $pdo = new \PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE "groups" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR)');
        $pdo->exec('CREATE TABLE "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR, "group" VARCHAR, "group_id" INTEGER)');

        $state = new ProjectState([
            new Table('groups', [
                new Column('id', 'int', false, null, true, true),
                new Column('name', 'string'),
            ]),
            new Table('users', [
                new Column('id', 'int', false, null, true, true),
                new Column('name', 'string'),
                new Column('group', 'string', true),
                new Column('group_id', 'int', true),
            ], [], [], [], [
                new \Nudelsalat\Schema\ForeignKeyConstraint('fk_users_group_id', 'group_id', 'groups', 'id', 'CASCADE'),
            ]),
        ]);

        $groupModel = (new StateRegistry($state, $pdo))->getModel('groups');
        $groupModel->insert(['name' => 'admins']);
        $model = (new StateRegistry($state, $pdo))->getModel('users');
        $model->insert(['name' => 'Ada', 'group' => 'admins', 'group_id' => 1]);
        $model->create(['name' => 'Grace', 'group' => 'staff', 'group_id' => 1]);
        $rows = $model->all();
        $this->assertSame('Ada', $rows[0]['name']);

        $model->update(['group' => 'staff'], ['name' => 'Ada']);
        $updatedRows = $model->all();
        $this->assertSame('staff', $updatedRows[0]['group']);
        $this->assertSame('Ada', $model->first(['group' => 'staff'])['name']);
        $this->assertSame(2, $model->count());
        $this->assertSame(0, $model->count(['group' => 'admins']));
        $this->assertTrue($model->exists(['name' => 'Grace']));
        $this->assertSame('Ada', $model->value('name', ['group' => 'staff']));
        $this->assertSame('Grace', $model->orderBy('name', 'DESC', 1)[0]['name']);
        $this->assertSame('admins', $model->related('group_id', $rows[0])['name']);
        $model->delete(['name' => 'Grace']);
        $this->assertSame(1, $model->count());
    }

    public function testStateRegistrySupportsAppAndModelLookupPatterns(): void
    {
        $databasePath = $this->createTemporaryDirectory() . '/registry.sqlite';
        $pdo = new \PDO('sqlite:' . $databasePath);
        $state = new ProjectState([
            new Table('authors', [new Column('id', 'int', false, null, true, true)], ['app_label' => 'library', 'model_name' => 'Author']),
            new Table('books', [new Column('id', 'int', false, null, true, true)], ['app_label' => 'library', 'model_name' => 'Book']),
        ]);

        $registry = new StateRegistry($state, $pdo);

        $this->assertTrue($registry->hasModel('library.Author'));
        $this->assertTrue($registry->hasModel('library', 'Book'));
        $this->assertSame('authors', $registry->getModel('library.Author')->getTableName());
        $this->assertSame('books', $registry->getModel('library', 'Book')->getTableName());
        $this->assertCount(2, $registry->getModels('library'));
    }

    public function testIntrospectorReadsSqliteTableInfoWithQuotedNames(): void
    {
        $databasePath = $this->createTemporaryDirectory() . '/introspector.sqlite';
        $pdo = new \PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE "parents" ("id" INTEGER PRIMARY KEY AUTOINCREMENT)');
        $pdo->exec('CREATE TABLE "odd name" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "label" VARCHAR, "parent_id" INTEGER, FOREIGN KEY("parent_id") REFERENCES "parents"("id") ON DELETE CASCADE)');
        $pdo->exec('CREATE INDEX "odd name_label_idx" ON "odd name"("label")');

        $table = (new Introspector($pdo))->getTable('odd name');
        $columns = $table->getColumns();
        $indexes = $table->getIndexes();
        $foreignKeys = $table->getForeignKeys();

        $this->assertTrue(isset($columns['id']));
        $this->assertTrue(isset($columns['label']));
        $this->assertTrue(isset($indexes['odd name_label_idx']));
        $this->assertCount(1, $foreignKeys);
    }
}
