<?php

namespace Nudelsalat\Tests\Cases;

use Nudelsalat\Migrations\Autodetector;
use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\Column;
use Nudelsalat\Schema\Constraint;
use Nudelsalat\Schema\ForeignKeyConstraint;
use Nudelsalat\Schema\Index;
use Nudelsalat\Schema\Table;
use Nudelsalat\Tests\Support\FakeQuestioner;
use Nudelsalat\Tests\TestCase;

class AutodetectorTest extends TestCase
{
    public function testDetectsFieldRenameWhenQuestionerConfirms(): void
    {
        $from = new ProjectState([
            new Table('users', [new Column('name', 'string')]),
        ]);
        $to = new ProjectState([
            new Table('users', [new Column('full_name', 'string')]),
        ]);

        $operations = (new Autodetector($from, $to, new FakeQuestioner(true, true)))->detectChanges();

        $this->assertCount(1, $operations);
        $this->assertInstanceOf(\Nudelsalat\Migrations\Operations\RenameField::class, $operations[0]);
    }

    public function testDetectsFieldAlteration(): void
    {
        $from = new ProjectState([
            new Table('users', [new Column('name', 'string', false)]),
        ]);
        $to = new ProjectState([
            new Table('users', [new Column('name', 'string', true)]),
        ]);

        $operations = (new Autodetector($from, $to, new FakeQuestioner()))->detectChanges();

        $this->assertCount(1, $operations);
        $this->assertInstanceOf(\Nudelsalat\Migrations\Operations\AlterField::class, $operations[0]);
    }

    public function testDetectsRelationalChangesInSafeOrder(): void
    {
        $oldTable = new Table(
            'orders',
            [new Column('user_id', 'int')],
            [],
            [new Index('orders_user_idx', ['user_id'])],
            [new Constraint('orders_user_unique', 'unique', ['user_id'])],
            [new ForeignKeyConstraint('fk_orders_user_id', 'user_id', 'users', 'id', 'CASCADE')]
        );
        $newTable = new Table(
            'orders',
            [new Column('user_id', 'int')],
            [],
            [new Index('orders_user_idx', ['user_id'], true)],
            [new Constraint('orders_user_check', 'check', [], 'user_id > 0')],
            [new ForeignKeyConstraint('fk_orders_user_id', 'user_id', 'users', 'id', 'RESTRICT')]
        );

        $operations = (new Autodetector(new ProjectState([$oldTable]), new ProjectState([$newTable]), new FakeQuestioner()))->detectChanges();
        $types = array_map(static fn(object $op): string => basename(str_replace('\\', '/', get_class($op))), $operations);

        $this->assertSame([
            'RemoveForeignKey',
            'RemoveConstraint',
            'RemoveIndex',
            'AddIndex',
            'AddConstraint',
            'AddForeignKey',
        ], $types);
    }

    public function testDetectsModelOptionOperations(): void
    {
        $from = new ProjectState([
            new Table('books', [new Column('id', 'int')], [
                'db_table' => 'books',
                'db_table_comment' => 'old comment',
                'unique_together' => [['slug', 'language']],
                'index_together' => [['title', 'language']],
                'order_with_respect_to' => null,
                'managers' => [['name' => 'objects', 'class' => 'DefaultManager']],
                'verbose_name' => 'book',
            ]),
        ]);
        $to = new ProjectState([
            new Table('books', [new Column('id', 'int')], [
                'db_table' => 'library_books',
                'db_table_comment' => 'new comment',
                'unique_together' => [['slug', 'edition']],
                'index_together' => [['title', 'edition']],
                'order_with_respect_to' => 'author_id',
                'managers' => [['name' => 'published', 'class' => 'PublishedManager']],
                'verbose_name' => 'library book',
            ]),
        ]);

        $operations = (new Autodetector($from, $to, new FakeQuestioner()))->detectChanges();
        $types = array_map(static fn(object $op): string => basename(str_replace('\\', '/', get_class($op))), $operations);

        $this->assertContains('AlterModelTable', implode(',', $types));
        $this->assertContains('AlterModelTableComment', implode(',', $types));
        $this->assertContains('AlterUniqueTogether', implode(',', $types));
        $this->assertContains('AlterIndexTogether', implode(',', $types));
        $this->assertContains('AlterOrderWithRespectTo', implode(',', $types));
        $this->assertContains('AlterModelManagers', implode(',', $types));
        $this->assertContains('AlterModelOptions', implode(',', $types));
    }

    public function testDetectsModelDelete(): void
    {
        $from = new ProjectState([
            new Table('users', [new Column('id', 'int')]),
        ]);
        $to = new ProjectState([]);

        $operations = (new Autodetector($from, $to, new FakeQuestioner()))->detectChanges();

        $this->assertCount(1, $operations);
        $this->assertInstanceOf(\Nudelsalat\Migrations\Operations\DeleteModel::class, $operations[0]);
    }

    public function testDetectsModelCreate(): void
    {
        $from = new ProjectState([]);
        $to = new ProjectState([
            new Table('users', [new Column('id', 'int')]),
        ]);

        $operations = (new Autodetector($from, $to, new FakeQuestioner()))->detectChanges();

        $this->assertCount(1, $operations);
        $this->assertInstanceOf(\Nudelsalat\Migrations\Operations\CreateModel::class, $operations[0]);
    }

    public function testDetectsAddedAndRemovedFields(): void
    {
        $from = new ProjectState([
            new Table('users', [
                new Column('id', 'int'),
                new Column('name', 'string'),
            ]),
        ]);
        $to = new ProjectState([
            new Table('users', [
                new Column('id', 'int'),
                new Column('email', 'string'),
            ]),
        ]);

        $operations = (new Autodetector($from, $to, new FakeQuestioner()))->detectChanges();
        
        $this->assertCount(1, $operations);
    }

    public function testDetectsFieldChangesWithDifferentTypes(): void
    {
        $from = new ProjectState([
            new Table('users', [
                new Column('id', 'int'),
                new Column('age', 'int'),
            ]),
        ]);
        $to = new ProjectState([
            new Table('users', [
                new Column('id', 'int'),
                new Column('age', 'string'),
            ]),
        ]);

        $operations = (new Autodetector($from, $to, new FakeQuestioner()))->detectChanges();
        
        $this->assertCount(1, $operations);
        $this->assertInstanceOf(\Nudelsalat\Migrations\Operations\AlterField::class, $operations[0]);
    }
}
