<?php

namespace Nudelsalat\Tests;

use Nudelsalat\ORM\Manager;
use Nudelsalat\ORM\Meta;
use Nudelsalat\ORM\Model;
use Nudelsalat\ORM\QuerySet;

class QuerySetExcludeTest extends TestCase
{
    public function testExcludeRemovesMatchingRows(): void
    {
        $pdo = $this->createSqliteAccountsTable();
        $qs = (new QuerySet(QuerySetAccount::class, $pdo))->orderBy('login');

        $results = $qs->exclude(['login' => 'a'])->all();
        $this->assertCount(2, $results);
        $this->assertSame('b', $results[0]->login);
        $this->assertSame('c', $results[1]->login);
    }

    public function testExcludeWithInClause(): void
    {
        $pdo = $this->createSqliteAccountsTable();
        $qs = (new QuerySet(QuerySetAccount::class, $pdo))->orderBy('login');

        $results = $qs->exclude(['login' => ['a', 'b']])->all();
        $this->assertCount(1, $results);
        $this->assertSame('c', $results[0]->login);
    }

    public function testExcludeGroupsAreIndependentAcrossCalls(): void
    {
        $pdo = $this->createSqliteAccountsTable();
        $qs = (new QuerySet(QuerySetAccount::class, $pdo))->orderBy('login');

        $results = $qs->exclude(['login' => 'a'])->exclude(['login' => 'b'])->all();
        $this->assertCount(1, $results);
        $this->assertSame('c', $results[0]->login);
    }

    public function testOrderByAcceptsDirectionArgument(): void
    {
        $pdo = $this->createSqliteAccountsTable();
        $qs = new QuerySet(QuerySetAccount::class, $pdo);

        $results = $qs->orderBy('login', 'DESC')->all();
        $this->assertSame('c', $results[0]->login);
        $this->assertSame('b', $results[1]->login);
        $this->assertSame('a', $results[2]->login);
    }

    public function testFilterSupportsNullComparison(): void
    {
        $pdo = $this->createSqliteAccountsTable();
        $qs = new QuerySet(QuerySetAccount::class, $pdo);

        $results = $qs->filter(['bio' => null])->orderBy('login')->all();
        $this->assertCount(1, $results);
        $this->assertSame('c', $results[0]->login);
    }

    private function createSqliteAccountsTable(): \PDO
    {
        $root = $this->createTemporaryDirectory();
        $dsn = 'sqlite:' . $root . '/qs.sqlite';
        $pdo = new \PDO($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $pdo->exec('CREATE TABLE accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            login VARCHAR(50) NOT NULL,
            bio VARCHAR(50) NULL
        )');

        $stmt = $pdo->prepare('INSERT INTO accounts (login, bio) VALUES (?, ?)');
        $stmt->execute(['a', 'bio-a']);
        $stmt->execute(['b', 'bio-b']);
        $stmt->execute(['c', null]);

        return $pdo;
    }
}

class QuerySetAccount extends Model
{
    // Override shared base-class statics for isolation in tests.
    public static Meta $meta;
    public static Manager $objects;

    public static array $options = [
        'db_table' => 'accounts',
    ];

    public static function fields(): array
    {
        return [];
    }
}

