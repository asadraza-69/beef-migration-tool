<?php

namespace Nudelsalat\Console;

use Nudelsalat\Bootstrap;
use Nudelsalat\Console\Commands\MakeCommand;
use Nudelsalat\Console\Commands\MigrateCommand;
use Nudelsalat\Console\Commands\ShowCommand;
use Nudelsalat\Console\Commands\SquashCommand;
use Nudelsalat\Console\Commands\CheckCommand;
use Nudelsalat\Console\Commands\SqlMigrateCommand;
use Nudelsalat\Console\Commands\IntrospectCommand;
use Nudelsalat\Console\Commands\MergeCommand;
use Nudelsalat\Console\Commands\OptimizeMigrationCommand;
use Nudelsalat\Console\Commands\DumpDataCommand;
use Nudelsalat\Console\Commands\LoadDataCommand;
use Nudelsalat\Console\Commands\SeederCommand;
use Nudelsalat\Console\Commands\ScaffoldCommand;

class Kernel
{
    private Bootstrap $bootstrap;
    private array $commands = [];

    public function __construct(Bootstrap $bootstrap)
    {
        $this->bootstrap = $bootstrap;
        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        $this->commands['makemigrations'] = new MakeCommand($this->bootstrap);
        $this->commands['migrate'] = new MigrateCommand($this->bootstrap);
        $this->commands['show'] = new ShowCommand($this->bootstrap);
        $this->commands['squash'] = new SquashCommand($this->bootstrap);
        $this->commands['check'] = new CheckCommand($this->bootstrap);
        $this->commands['sqlmigrate'] = new SqlMigrateCommand($this->bootstrap);
        $this->commands['introspect'] = new IntrospectCommand($this->bootstrap);
        $this->commands['merge'] = new MergeCommand($this->bootstrap);
        $this->commands['optimize'] = new OptimizeMigrationCommand($this->bootstrap);
        $this->commands['dumpdata'] = new DumpDataCommand($this->bootstrap);
        $this->commands['loaddata'] = new LoadDataCommand($this->bootstrap);
        $this->commands['seed'] = new SeederCommand($this->bootstrap);
        $this->commands['scaffold'] = new ScaffoldCommand($this->bootstrap);
    }

    public function run(array $argv): void
    {
        $commandName = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if ($commandName === 'help' || !isset($this->commands[$commandName])) {
            $this->displayHelp();
            return;
        }

        $this->commands[$commandName]->execute($args);
    }

    private function displayHelp(): void
    {
        echo "Nudelsalat Migrations CLI\n";
        echo "Usage: php nudelsalat <command> [options]\n\n";
        echo "Available commands:\n";
        echo "  makemigrations  Detect changes and create a new migration\n";
        echo "  migrate         Apply pending migrations (--fake supported)\n";
        echo "  show            Display applied and pending migrations\n";
        echo "  squash          Combine multiple migrations into one\n";
        echo "  check           Verify that models and migrations are in sync\n";
        echo "  sqlmigrate      Display the raw SQL for a specific migration\n";
        echo "  introspect      Scan database and generate Model classes\n";
        echo "  merge           Reconcile divergent migration branches\n";
        echo "  optimize        Optimize a migration file in place\n";
        echo "  dumpdata        Export model data to JSON/XML fixture\n";
        echo "  loaddata        Import data from JSON/XML/YAML fixture\n";
        echo "  seed            Run database seeders\n";
        echo "  scaffold        Generate full database migrations (tables, FKs, functions, views, triggers, indexes)\n";
        echo "  help            Display this help message\n";
    }
}
