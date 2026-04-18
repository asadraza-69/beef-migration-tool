<?php

namespace Nudelsalat\Tests\Cases;

use Nudelsalat\Migrations\Loader;
use Nudelsalat\Migrations\MigrationWriter;
use Nudelsalat\Migrations\Recorder;
use Nudelsalat\Migrations\Squasher;
use Nudelsalat\Migrations\Operations\AddConstraint;
use Nudelsalat\Migrations\Operations\AddIndex;
use Nudelsalat\Schema\Constraint;
use Nudelsalat\Schema\Index;
use Nudelsalat\Tests\Support\TestProject;
use Nudelsalat\Tests\TestCase;

class LoaderWriterTest extends TestCase
{
    public function testLoaderBuildsReplacementMapsAndQualifiedDependencies(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0001_initial') extends Migration {};
PHP);
        $project->writeMigration('0002_second', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0002_second') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->dependencies = ['0001_initial'];
    }
};
PHP);
        $project->writeMigration('0003_squashed', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0003_squashed') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->dependencies = [];
        $this->replaces = ['0001_initial', '0002_second'];
    }
};
PHP);

        $loader = new Loader();
        $graph = $loader->loadDisk(['main' => $project->migrationsDir]);

        $this->assertTrue(isset($graph->nodes['main.0003_squashed']));
        $this->assertSame(['main.0001_initial', 'main.0002_second'], $loader->getReplacementMap()['main.0003_squashed']);
        $this->assertSame('main.0003_squashed', $loader->getReplacedByMap()['main.0001_initial']);
        $this->assertSame(['main.0003_squashed'], $graph->dependencies['main.0002_second']);
    }

    public function testWriterEmitsImportsAndReplaces(): void
    {
        $writer = new MigrationWriter([
            new AddIndex('users', new Index('users_name_idx', ['name'])),
            new AddConstraint('users', new Constraint('users_name_unique', 'unique', ['name'])),
        ], ['0001_initial'], '0002_indexes', ['0001_initial']);

        $php = $writer->asPhp();

        $this->assertContains('use Nudelsalat\\Migrations\\Operations\\AddIndex;', $php);
        $this->assertContains('use Nudelsalat\\Schema\\Index;', $php);
        $this->assertContains('$this->replaces = array', $php);
        $this->assertContains('new AddConstraint(', $php);
    }

    public function testLoaderSelectsReplacementOnlyWhenNoOriginalsAreApplied(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0001_initial') extends Migration {};
PHP);
        $project->writeMigration('0002_second', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0002_second') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->dependencies = ['0001_initial'];
    }
};
PHP);
        $project->writeMigration('0003_squashed', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0003_squashed') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->replaces = ['0001_initial', '0002_second'];
    }
};
PHP);

        $loader = new Loader();
        $loader->loadDisk(['main' => $project->migrationsDir]);

        $this->assertSame(['main.0003_squashed'], $loader->getActiveNodeNames([]));
        $this->assertSame(['main.0001_initial', 'main.0002_second'], $loader->getActiveNodeNames(['main.0001_initial']));
    }

    public function testLoaderDetectsConflictingHeadsPerApp(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0001_initial') extends Migration {};
PHP);
        $project->writeMigration('0002_branch_a', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0002_branch_a') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->dependencies = ['0001_initial'];
    }
};
PHP);
        $project->writeMigration('0002_branch_b', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0002_branch_b') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->dependencies = ['0001_initial'];
    }
};
PHP);

        $loader = new Loader();
        $loader->loadDisk(['main' => $project->migrationsDir]);

        $conflicts = $loader->detectConflicts();
        $this->assertSame(['main' => ['main.0002_branch_a', 'main.0002_branch_b']], $conflicts);
        $this->assertThrows(\Nudelsalat\Migrations\Exceptions\ConflictError::class, fn() => $loader->ensureNoConflicts());
    }

    public function testLoaderDetectsInconsistentAppliedHistory(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0001_initial') extends Migration {};
PHP);
        $project->writeMigration('0002_second', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0002_second') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->dependencies = ['0001_initial'];
    }
};
PHP);

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $project->root . '/history.sqlite']);
        $loader = $bootstrap->createLoader();
        $recorder = new Recorder($bootstrap->getPdo());
        $recorder->ensureSchema();
        $recorder->recordApplied('main.0002_second', 'checksum');

        $this->assertThrows(
            \Nudelsalat\Migrations\Exceptions\InconsistentMigrationHistory::class,
            fn() => $loader->ensureConsistentHistory($recorder->getAppliedMigrations()),
            'applied before its dependency'
        );
    }

    public function testLoaderRespectsRunBeforeMetadata(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0001_initial') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->runBefore = ['0002_late'];
    }
};
PHP);
        $project->writeMigration('0002_late', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0002_late') extends Migration {};
PHP);

        $loader = new Loader();
        $graph = $loader->loadDisk(['main' => $project->migrationsDir]);

        $this->assertSame(['main.0001_initial'], $graph->dependencies['main.0002_late']);
    }

    public function testLoaderDetectsInconsistentMixedReplacementHistory(): void
    {
        $project = new TestProject($this->createTemporaryDirectory());
        $project->writeMigration('0001_initial', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0001_initial') extends Migration {};
PHP);
        $project->writeMigration('0002_second', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0002_second') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->dependencies = ['0001_initial'];
    }
};
PHP);
        $project->writeMigration('0003_squashed', <<<'PHP'
<?php
use Nudelsalat\Migrations\Migration;
return new class('0003_squashed') extends Migration {
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->replaces = ['0001_initial', '0002_second'];
    }
};
PHP);

        $bootstrap = $project->createBootstrap(['dsn' => 'sqlite:' . $project->root . '/mixed_replacement.sqlite']);
        $loader = $bootstrap->createLoader();
        $recorder = new Recorder($bootstrap->getPdo());
        $recorder->ensureSchema();
        $recorder->recordApplied('main.0001_initial', 'checksum-1');
        $recorder->recordApplied('main.0003_squashed', 'checksum-3');

        $this->assertThrows(
            \Nudelsalat\Migrations\Exceptions\InconsistentMigrationHistory::class,
            fn() => $loader->ensureConsistentHistory($recorder->getAppliedMigrations()),
            'only part of its replaced history'
        );
    }

    public function testWriterEmitsAtomicInitialAndRunBeforeMetadata(): void
    {
        $writer = new MigrationWriter([], [], '0001_initial', [], true, false, ['0002_late']);
        $php = $writer->asPhp();

        $this->assertContains('$this->atomic = false;', $php);
        $this->assertContains('$this->initial = true;', $php);
        $this->assertContains('$this->runBefore = array', $php);
    }

    public function testSquasherPreservesRunBeforeOutsideSquashedSet(): void
    {
        $first = new class('main.0001_initial') extends \Nudelsalat\Migrations\Migration {
            public function __construct(string $name)
            {
                parent::__construct($name);
                $this->runBefore = ['main.0003_future'];
            }
        };
        $second = new class('main.0002_second') extends \Nudelsalat\Migrations\Migration {
            public function __construct(string $name)
            {
                parent::__construct($name);
                $this->dependencies = ['main.0001_initial'];
                $this->runBefore = ['main.0002_second'];
            }
        };

        $squashed = (new Squasher())->squash([$first, $second], 'main.0003_squashed');

        $this->assertSame(['main.0003_future'], $squashed->runBefore);
    }
}
