<?php

namespace Nudelsalat\Tests\Cases;

use Nudelsalat\Console\Commands\ScaffoldCommand;
use Nudelsalat\Schema\Introspector;
use Nudelsalat\Tests\Support\TestProject;
use Nudelsalat\Tests\TestCase;

class SchemaFilterTest extends TestCase
{
    public function testIntrospectorAcceptsSchemaParameter(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $pdo = new \PDO('sqlite:' . $project->root . '/test.sqlite');
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        
        $introspector = new Introspector($pdo, 'custom_schema');
        
        $this->assertEquals('custom_schema', $introspector->getSchema());
    }

    public function testIntrospectorSetSchemaMethod(): void
    {
        $pdo = new \PDO('sqlite:/tmp/test_' . time() . '.sqlite');
        $introspector = new Introspector($pdo);
        
        $introspector->setSchema('my_schema');
        $this->assertEquals('my_schema', $introspector->getSchema());
        
        $introspector->setSchema(null);
        $this->assertEquals(null, $introspector->getSchema());
    }

    public function testScaffoldCommandOutputsToStdoutWithoutOutputDir(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $project->root . '/scaffold.sqlite']);
        
        $command = new ScaffoldCommand($bootstrap);
        
        $output = $this->captureOutput(fn() => $command->execute([]));
        
        $this->assertContains('PostgreSQL', $output);
    }

    public function testScaffoldCommandShowsSchemaInfo(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $project->root . '/scaffold2.sqlite']);
        
        $command = new ScaffoldCommand($bootstrap);
        
        $output = $this->captureOutput(fn() => $command->execute(['--schema=public']));
        
        $this->assertContains('PostgreSQL', $output);
    }
}