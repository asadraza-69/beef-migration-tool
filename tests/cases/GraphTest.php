<?php

namespace Nudelsalat\Tests\Cases;

use Nudelsalat\Migrations\Graph;
use Nudelsalat\Migrations\Migration;
use Nudelsalat\Tests\TestCase;

class GraphTest extends TestCase
{
    public function testForwardsAndBackwardsPlansRespectDependencies(): void
    {
        $graph = new Graph();
        $graph->addNode('main.0001', $this->migration('main.0001'));
        $graph->addNode('main.0002', $this->migration('main.0002'));
        $graph->addNode('main.0003', $this->migration('main.0003'));
        $graph->addDependency('main.0002', 'main.0001');
        $graph->addDependency('main.0003', 'main.0002');

        $this->assertSame(['main.0001', 'main.0002', 'main.0003'], $graph->forwards_plan('main.0003'));
        $this->assertSame(['main.0003', 'main.0002', 'main.0001'], $graph->backwards_plan('main.0001'));
    }

    public function testMissingDependencyFailsValidation(): void
    {
        $graph = new Graph();
        $graph->addNode('main.0002', $this->migration('main.0002'));
        $graph->addDependency('main.0002', 'main.0001');

        $this->assertThrows(\Nudelsalat\Migrations\Exceptions\DependencyError::class, fn() => $graph->validate(), 'depends on missing migration');
    }

    public function testCircularDependencyFailsValidation(): void
    {
        $graph = new Graph();
        $graph->addNode('main.0001', $this->migration('main.0001'));
        $graph->addNode('main.0002', $this->migration('main.0002'));
        $graph->addDependency('main.0001', 'main.0002');
        $graph->addDependency('main.0002', 'main.0001');

        $this->assertThrows(\Nudelsalat\Migrations\Exceptions\CircularDependencyError::class, fn() => $graph->validate(), 'Circular migration dependency');
    }

    private function migration(string $name): Migration
    {
        return new class($name) extends Migration {
        };
    }
}
