<?php

namespace Nudelsalat\Tests;

use Nudelsalat\Bootstrap;
use Nudelsalat\Config;
use Nudelsalat\Migrations\Fields\AutoField;
use Nudelsalat\Migrations\Fields\StringField;
use Nudelsalat\Migrations\Fields\IntField;
use Nudelsalat\Migrations\Fields\ForeignKey;
use Nudelsalat\Migrations\Fields\OneToOneField;
use Nudelsalat\Migrations\Fields\ManyToManyField;
use Nudelsalat\ORM\Paginator;
use Nudelsalat\ORM\atomic;
use Nudelsalat\ORM\Aggregation;
use Nudelsalat\ORM\Model;
use Nudelsalat\ORM\Manager;
use Nudelsalat\ORM\QuerySet;
use Nudelsalat\ORM\Signal;
use Nudelsalat\ORM\AbstractModel;
use Nudelsalat\ORM\ProxyModel;

class ORMTest extends TestCase
{
    private $bootstrap;
    private $testTableCreated = false;

    public function setUp(): void
    {
        $configArray = [
            'database' => [
                'driver' => 'pgsql',
                'dsn' => 'pgsql:host=localhost;port=5432;dbname=nudelsalat_test',
                'user' => 'nudelsalat',
                'pass' => 'nudelsalat',
            ],
            'apps' => [
                'main' => [
                    'models' => __DIR__ . '/Support/TestProject/Models',
                    'migrations' => __DIR__ . '/Support/TestProject/Database/Migrations',
                ],
            ],
        ];

        $config = new Config($configArray);
        $this->bootstrap = new Bootstrap($config);
        Bootstrap::setInstance($this->bootstrap);

        $this->createTestTable();
    }

    public function tearDown(): void
    {
        if ($this->testTableCreated) {
            try {
                $pdo = $this->bootstrap->getPdo();
                $pdo->exec("DROP TABLE IF EXISTS orm_test CASCADE");
                $pdo->exec("DROP TABLE IF EXISTS orm_child_test CASCADE");
            } catch (\Throwable $e) {
            }
        }
    }

    private function createTestTable(): void
    {
        try {
            $pdo = $this->bootstrap->getPdo();
            $pdo->exec("DROP TABLE IF EXISTS orm_test CASCADE");
            $pdo->exec("CREATE TABLE orm_test (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(255),
                status VARCHAR(50) DEFAULT 'active'
            )");
            $pdo->exec("DROP TABLE IF EXISTS orm_child_test CASCADE");
            $pdo->exec("CREATE TABLE orm_child_test (
                id SERIAL PRIMARY KEY,
                orm_test_id INTEGER REFERENCES orm_test(id),
                extra VARCHAR(100)
            )");
            $this->testTableCreated = true;
        } catch (\Throwable $e) {
            $this->markSkipped('Cannot connect to database: ' . $e->getMessage());
        }
    }

    // ============================================================
    // Test Model Basic Operations
    // ============================================================

    public function testModelGetTableName(): void
    {
        $table = TestModel::getTableName();
        $this->assertSame('orm_test', $table);
    }

    public function testModelGetTableNameWithDbTable(): void
    {
        $table = TestModelWithDbTable::getTableName();
        $this->assertSame('custom_table', $table);
    }

    public function testModelGetPkName(): void
    {
        $pk = TestModel::getPkName();
        $this->assertSame('id', $pk);
    }

    public function testModelUsesAutoIncrement(): void
    {
        $uses = TestModel::usesAutoIncrement();
        $this->assertTrue($uses);
    }

    // ============================================================
    // Test Manager CRUD
    // ============================================================

    public function testManagerCreate(): void
    {
        $manager = TestModel::getObjectManager();
        
        $record = $manager->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->assertTrue($record['id'] > 0);
        $this->assertSame('Test User', $record['name']);
        $this->assertSame('test@example.com', $record['email']);

        TestModel::delete(['id' => $record['id']]);
    }

    public function testManagerGet(): void
    {
        $created = TestModel::create([
            'name' => 'Get Test',
            'email' => 'get@test.com',
        ]);

        $manager = TestModel::getObjectManager();
        $record = $manager->get($created['id']);

        $this->assertSame('Get Test', $record['name']);

        TestModel::delete(['id' => $created['id']]);
    }

    public function testManagerFilter(): void
    {
        TestModel::create(['name' => 'Filter 1', 'email' => 'f1@test.com']);
        TestModel::create(['name' => 'Filter 2', 'email' => 'f2@test.com']);

        $manager = TestModel::getObjectManager();
        $results = $manager->filter(['name' => 'Filter 1']);

        $this->assertCount(1, $results);

        TestModel::delete([]);
    }

    public function testManagerUpdate(): void
    {
        $created = TestModel::create(['name' => 'Before', 'email' => 'before@test.com']);

        $manager = TestModel::getObjectManager();
        $count = $manager->update(['name' => 'After'], ['id' => $created['id']]);

        $this->assertSame(1, $count);

        $updated = TestModel::get($created['id']);
        $this->assertSame('After', $updated['name']);

        TestModel::delete(['id' => $created['id']]);
    }

    public function testManagerDelete(): void
    {
        $created = TestModel::create(['name' => 'To Delete', 'email' => 'del@test.com']);

        $manager = TestModel::getObjectManager();
        $count = $manager->delete(['id' => $created['id']]);

        $this->assertSame(1, $count);
    }

    public function testManagerCount(): void
    {
        TestModel::create(['name' => 'Count 1', 'email' => 'c1@test.com']);
        TestModel::create(['name' => 'Count 2', 'email' => 'c2@test.com']);

        $manager = TestModel::getObjectManager();
        $count = $manager->count();

        $this->assertTrue($count >= 2);

        TestModel::delete([]);
    }

    public function testManagerExists(): void
    {
        TestModel::create(['name' => 'Exists Test', 'email' => 'ex@test.com']);

        $manager = TestModel::getObjectManager();
        $exists = $manager->exists();

        $this->assertTrue($exists);

        TestModel::delete([]);
    }

    // ============================================================
    // Test Static CRUD Methods
    // ============================================================

    public function testModelCreate(): void
    {
        $record = TestModel::create([
            'name' => 'Static Create',
            'email' => 'static@test.com',
        ]);

        $this->assertTrue($record['id'] > 0);

        TestModel::delete(['id' => $record['id']]);
    }

    public function testModelAll(): void
    {
        TestModel::create(['name' => 'All 1', 'email' => 'a1@test.com']);
        TestModel::create(['name' => 'All 2', 'email' => 'a2@test.com']);

        $all = TestModel::all();

        $this->assertTrue(count($all) >= 2);

        TestModel::delete([]);
    }

    public function testModelFilter(): void
    {
        $created = TestModel::create(['name' => 'Static Filter', 'email' => 'sf@test.com']);

        $results = TestModel::filter(['name' => 'Static Filter']);

        $this->assertCount(1, $results);

        TestModel::delete(['id' => $created['id']]);
    }

    public function testModelGet(): void
    {
        $created = TestModel::create(['name' => 'Get Static', 'email' => 'gs@test.com']);

        $record = TestModel::get($created['id']);

        $this->assertSame('Get Static', $record['name']);

        TestModel::delete(['id' => $created['id']]);
    }

    public function testModelUpdate(): void
    {
        $created = TestModel::create(['name' => 'Update Me', 'email' => 'um@test.com']);

        $count = TestModel::update(['name' => 'Updated'], ['id' => $created['id']]);

        $this->assertSame(1, $count);

        TestModel::delete(['id' => $created['id']]);
    }

    public function testModelDelete(): void
    {
        $created = TestModel::create(['name' => 'Delete Me', 'email' => 'dm@test.com']);

        $count = TestModel::delete(['id' => $created['id']]);

        $this->assertSame(1, $count);
    }

    public function testModelCount(): void
    {
        TestModel::create(['name' => 'Count Me', 'email' => 'cm@test.com']);

        $count = TestModel::count();

        $this->assertTrue($count >= 1);

        TestModel::delete([]);
    }

    public function testModelExists(): void
    {
        $created = TestModel::create(['name' => 'Exists Check', 'email' => 'ec@test.com']);

        $exists = TestModel::exists();

        $this->assertTrue($exists);

        TestModel::delete(['id' => $created['id']]);
    }

    // ============================================================
    // Test QuerySet
    // ============================================================

    public function testQuerySetFilter(): void
    {
        TestModel::create(['name' => 'QS 1', 'email' => 'qs1@test.com']);
        TestModel::create(['name' => 'QS 2', 'email' => 'qs2@test.com']);

        $qs = TestModel::objects();
        $results = $qs->filter(['name' => 'QS 1'])->all();

        $this->assertCount(1, $results);

        TestModel::delete([]);
    }

    public function testQuerySetOrderBy(): void
    {
        TestModel::create(['name' => 'Z User', 'email' => 'z@test.com']);
        TestModel::create(['name' => 'A User', 'email' => 'a@test.com']);

        $qs = TestModel::objects();
        $results = $qs->filter([])->orderBy('name')->all();

        $this->assertSame('A User', $results[0]['name']);

        TestModel::delete([]);
    }

    public function testQuerySetLimit(): void
    {
        TestModel::create(['name' => 'Limit 1', 'email' => 'l1@test.com']);
        TestModel::create(['name' => 'Limit 2', 'email' => 'l2@test.com']);
        TestModel::create(['name' => 'Limit 3', 'email' => 'l3@test.com']);

        $qs = TestModel::objects();
        $results = $qs->filter([])->limit(2)->all();

        $this->assertCount(2, $results);

        TestModel::delete([]);
    }

    public function testQuerySetOffset(): void
    {
        TestModel::create(['name' => 'Off 1', 'email' => 'o1@test.com']);
        TestModel::create(['name' => 'Off 2', 'email' => 'o2@test.com']);
        TestModel::create(['name' => 'Off 3', 'email' => 'o3@test.com']);

        $qs = TestModel::objects();
        $results = $qs->filter([])->offset(1)->limit(1)->all();

        $this->assertCount(1, $results);

        TestModel::delete([]);
    }

    public function testQuerySetFirst(): void
    {
        TestModel::create(['name' => 'First', 'email' => 'first@test.com']);

        $qs = TestModel::objects();
        $first = $qs->filter(['name' => 'First'])->first();

        $this->assertSame('First', $first['name']);

        TestModel::delete([]);
    }

    // ============================================================
    // Test Bulk Operations
    // ============================================================

    public function testBulkCreate(): void
    {
        $records = [
            ['name' => 'Bulk 1', 'email' => 'b1@test.com'],
            ['name' => 'Bulk 2', 'email' => 'b2@test.com'],
            ['name' => 'Bulk 3', 'email' => 'b3@test.com'],
        ];

        $created = TestModel::bulkCreate($records);

        $this->assertCount(3, $created);

        TestModel::delete([]);
    }

    public function testBulkUpdate(): void
    {
        $records = [
            ['name' => 'BU 1', 'email' => 'bu1@test.com'],
            ['name' => 'BU 2', 'email' => 'bu2@test.com'],
        ];

        $created = TestModel::bulkCreate($records);

        $toUpdate = [
            ['id' => $created[0]['id'], 'name' => 'BU 1 Updated'],
            ['id' => $created[1]['id'], 'name' => 'BU 2 Updated'],
        ];

        $count = TestModel::bulkUpdate($toUpdate, ['name']);

        $this->assertSame(2, $count);

        TestModel::delete([]);
    }

    // ============================================================
    // Test AbstractModel and ProxyModel
    // ============================================================

    public function testAbstractModelIsAbstract(): void
    {
        $isAbstract = TestAbstractModel::isAbstract();
        $this->assertTrue($isAbstract);
    }

    public function testAbstractModelGetTableName(): void
    {
        $table = TestAbstractModel::getTableName();
        $this->assertSame(null, $table);
    }

    public function testProxyModelIsProxy(): void
    {
        $isProxy = TestProxyModel::isProxy();
        $this->assertTrue($isProxy);
    }

    public function testProxyModelGetTableName(): void
    {
        $table = TestProxyModel::getTableName();
        $this->assertSame('orm_test', $table);
    }

    // ============================================================
    // Test Validation
    // ============================================================

    public function testModelValidate(): void
    {
        $errors = TestModel::validate([
            'id' => 1,
            'name' => 'Valid Name',
            'email' => 'valid@test.com',
        ]);

        $this->assertCount(0, $errors);
    }

    public function testModelValidateNullRequiredField(): void
    {
        $errors = TestModel::validate([
            'id' => 1,
            'name' => null,
            'email' => 'test@test.com',
        ]);

        $this->assertCount(1, $errors);
        $this->assertTrue(isset($errors['name']));
    }

    // ============================================================
    // Test Signals
    // ============================================================

    public function testSignalConnectAndSend(): void
    {
        $fired = false;
        
        Signal::connect('post_save', function($model, $result, $created) use (&$fired) {
            $fired = true;
        });

        $record = TestModel::create([
            'name' => 'Signal Test',
            'email' => 'signal@test.com',
        ]);

        $this->assertTrue($fired);

        TestModel::delete(['id' => $record['id']]);
    }

    public function testSignalPreDelete(): void
    {
        $fired = false;
        
        Signal::connect('post_delete', function($model, $conditions, $count) use (&$fired) {
            $fired = true;
        });

        $created = TestModel::create([
            'name' => 'Delete Signal',
            'email' => 'ds@test.com',
        ]);

        TestModel::delete(['id' => $created['id']]);

        $this->assertTrue($fired);
    }

    // ============================================================
    // Test Field Types
    // ============================================================

    public function testAutoField(): void
    {
        $field = new AutoField();
        $this->assertTrue($field->primaryKey);
        $this->assertTrue($field->autoIncrement);
    }

    public function testStringField(): void
    {
        $field = new StringField(100);
        $this->assertSame(100, $field->maxLength);
    }

    public function testOneToOneField(): void
    {
        $field = new OneToOneField(TestModel::class);
        $this->assertTrue($field->isUnique());
    }

    public function testManyToManyField(): void
    {
        $field = new ManyToManyField(TestModel::class);
        $this->assertSame('virtual', $field->getType());
    }

    public function testForeignKeyGetDbTable(): void
    {
        $field = new ForeignKey(TestModel::class);
        $table = $field->getDbTable('TestRelated');
        $this->assertSame('testrelated_orm_test', $table);
    }
}

// ============================================================
// Test Models
// ============================================================

class TestModel extends Model
{
    public static function fields(): array
    {
        return [
            'id' => new AutoField(),
            'name' => new StringField(100),
            'email' => new StringField(255),
            'status' => new StringField(50, default: 'active'),
        ];
    }
}

class TestModelWithDbTable extends Model
{
    public static array $options = [
        'db_table' => 'custom_table',
    ];

    public static function fields(): array
    {
        return [
            'id' => new AutoField(),
            'name' => new StringField(100),
        ];
    }
}

class TestRelated extends Model
{
    public static function fields(): array
    {
        return [
            'id' => new AutoField(),
            'name' => new StringField(100),
        ];
    }
}

abstract class TestAbstractModel extends AbstractModel
{
    public static function fields(): array
    {
        return [
            'id' => new AutoField(),
            'name' => new StringField(100),
        ];
    }
}

class TestProxyModel extends ProxyModel
{
    public static array $options = [
        'proxy' => true,
        'proxy_for' => TestModel::class,
    ];

    public static function fields(): array
    {
        return TestModel::fields();
    }
}

// ============================================================
// Tests for new ORM features
// ============================================================

class ORMFeaturesTest extends TestCase
{
    private $bootstrap;

    public function setUp(): void
    {
        $configArray = [
            'database' => [
                'driver' => 'pgsql',
                'dsn' => 'pgsql:host=localhost;port=5432;dbname=nudelsalat_test',
                'user' => 'nudelsalat',
                'pass' => 'nudelsalat',
            ],
            'apps' => [
                'main' => [
                    'models' => __DIR__ . '/Support/TestProject/Models',
                    'migrations' => __DIR__ . '/Support/TestProject/Database/Migrations',
                ],
            ],
        ];

        $config = new Config($configArray);
        $this->bootstrap = new Bootstrap($config);
        Bootstrap::setInstance($this->bootstrap);

        try {
            $pdo = $this->bootstrap->getPdo();
            $pdo->exec("DROP TABLE IF EXISTS orm_features_test CASCADE");
            $pdo->exec("CREATE TABLE orm_features_test (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(255),
                status VARCHAR(50) DEFAULT 'active'
            )");
        } catch (\Throwable $e) {
            $this->markSkipped('Cannot connect to database: ' . $e->getMessage());
        }
    }

    public function tearDown(): void
    {
        try {
            $pdo = $this->bootstrap->getPdo();
            $pdo->exec("DROP TABLE IF EXISTS orm_features_test");
        } catch (\Throwable $e) {
        }
    }

    public function testModelAggregate(): void
    {
        $pdo = $this->bootstrap->getPdo();
        $pdo->exec("INSERT INTO orm_features_test (name, email, status) VALUES 
            ('test1', 't1@t.com', 'active'),
            ('test2', 't2@t.com', 'active'),
            ('test3', 't3@t.com', 'inactive')");

        $result = TestModel::aggregate([new Aggregation('COUNT', 'id', 'total')]);
        
        $this->assertSame(3, $result['total']);
    }

    public function testModelRaw(): void
    {
        $pdo = $this->bootstrap->getPdo();
        $pdo->exec("INSERT INTO orm_features_test (name, email, status) VALUES 
            ('rawtest', 'raw@t.com', 'active')");

        $result = TestModel::raw("SELECT * FROM orm_features_test WHERE name = 'rawtest'");
        
        $this->assertCount(1, $result);
        $this->assertSame('rawtest', $result[0]['name']);
    }

    public function testModelRawWithParams(): void
    {
        $pdo = $this->bootstrap->getPdo();
        $pdo->exec("INSERT INTO orm_features_test (name, email, status) VALUES 
            ('paramtest', 'param@t.com', 'active')");

        $result = TestModel::raw(
            "SELECT * FROM orm_features_test WHERE name = ?",
            ['paramtest']
        );
        
        $this->assertCount(1, $result);
    }

    public function testModelGetOrCreate(): void
    {
        $pdo = $this->bootstrap->getPdo();
        $pdo->exec("INSERT INTO orm_features_test (name, email, status) VALUES 
            ('existing', 'exists@t.com', 'active')");

        // Get existing
        $result = TestModel::getOrCreate(
            ['name' => 'existing'],
            ['email' => 'new@t.com']
        );
        $this->assertSame('existing', $result['name']);

        // Create new
        $result = TestModel::getOrCreate(
            ['name' => 'newuser'],
            ['email' => 'new@t.com', 'status' => 'active']
        );
        $this->assertSame('newuser', $result['name']);
    }

    public function testModelUpdateOrCreate(): void
    {
        $pdo = $this->bootstrap->getPdo();
        $pdo->exec("INSERT INTO orm_features_test (name, email, status) VALUES 
            ('updatetest', 'old@t.com', 'active')");

        // Update existing
        $result = TestModel::updateOrCreate(
            ['name' => 'updatetest'],
            ['email' => 'new@t.com']
        );
        $this->assertSame('new@t.com', $result['email']);

        // Create new
        $result = TestModel::updateOrCreate(
            ['name' => 'brandnew'],
            ['email' => 'brandnew@t.com', 'status' => 'active']
        );
        $this->assertSame('brandnew', $result['name']);
    }

    public function testPaginator(): void
    {
        $pdo = $this->bootstrap->getPdo();
        $pdo->exec("INSERT INTO orm_features_test (name, email, status) VALUES 
            ('p1', 'p1@t.com', 'active'),
            ('p2', 'p2@t.com', 'active'),
            ('p3', 'p3@t.com', 'active'),
            ('p4', 'p4@t.com', 'active'),
            ('p5', 'p5@t.com', 'active')");

        $items = TestModel::all();
        $paginator = new Paginator($items, 2, 1);

        $this->assertSame(3, $paginator->getTotalPages());
        $this->assertCount(2, $paginator->getPage(1));
        $this->assertTrue($paginator->hasNextPage());
        $this->assertFalse($paginator->hasPreviousPage());

        $page2 = $paginator->getPage(2);
        $this->assertCount(2, $page2);
    }

    public function testAtomicTransaction(): void
    {
        $pdo = $this->bootstrap->getPdo();
        
        $result = atomic($pdo, function() use ($pdo) {
            $stmt = $pdo->prepare("INSERT INTO orm_features_test (name, email, status) VALUES (?, ?, ?)");
            $stmt->execute(['atomic', 'atomic@t.com', 'active']);
            return true;
        });
        
        $this->assertTrue($result);
        
        // Verify the record was inserted
        $verify = TestModel::raw("SELECT * FROM orm_features_test WHERE name = 'atomic'");
        $this->assertCount(1, $verify);
    }

    public function testAtomicRollback(): void
    {
        $pdo = $this->bootstrap->getPdo();
        $initialCount = TestModel::count();
        
        $this->assertThrows(\Throwable::class, function() use ($pdo) {
            atomic($pdo, function() use ($pdo) {
                $stmt = $pdo->prepare("INSERT INTO orm_features_test (name, email, status) VALUES (?, ?, ?)");
                $stmt->execute(['rollback', 'rb@t.com', 'active']);
                throw new \RuntimeException('Test rollback');
            });
        });
        
        // Verify no record was inserted due to rollback
        $verify = TestModel::raw("SELECT * FROM orm_features_test WHERE name = 'rollback'");
        $this->assertCount(0, $verify);
    }

    public function testAggregationWithSum(): void
    {
        $pdo = $this->bootstrap->getPdo();
        $pdo->exec("DROP TABLE IF EXISTS orm_agg_test CASCADE");
        $pdo->exec("CREATE TABLE orm_agg_test (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100),
            price DECIMAL(10,2)
        )");
        $pdo->exec("INSERT INTO orm_agg_test (name, price) VALUES 
            ('item1', 10.50),
            ('item2', 20.25),
            ('item3', 15.00)");

        // Need to use raw SQL for aggregation since table is different
        $result = TestModel::raw("SELECT SUM(price) as total FROM orm_agg_test");
        
        $this->assertNotNull($result[0]['total'] ?? null);
    }
}