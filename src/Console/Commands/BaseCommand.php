<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Bootstrap;

abstract class BaseCommand
{
    protected Bootstrap $bootstrap;
    protected \Nudelsalat\Console\ConsoleWriter $writer;

    public function __construct(Bootstrap $bootstrap)
    {
        $this->bootstrap = $bootstrap;
        $this->writer = new \Nudelsalat\Console\ConsoleWriter();
    }

    abstract public function execute(array $args): void;

    protected function info(string $msg): void
    {
        $this->writer->info($msg);
    }

    protected function error(string $msg): void
    {
        $this->writer->error($msg);
    }

    protected function hasFlag(array $args, string $flag): bool
    {
        return in_array($flag, $args, true);
    }

    protected function getOptionValue(array $args, string $name): ?string
    {
        $prefix = $name . '=';
        foreach ($args as $arg) {
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }

    protected function getPositionalArgs(array $args): array
    {
        return array_values(array_filter($args, static fn(string $arg): bool => !str_starts_with($arg, '--')));
    }
}
