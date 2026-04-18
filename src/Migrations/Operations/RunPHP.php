<?php

namespace Nudelsalat\Migrations\Operations;

use Nudelsalat\Migrations\ProjectState;
use Nudelsalat\Schema\SchemaEditor;
use Nudelsalat\Migrations\StateRegistry;

class RunPHP extends Operation
{
    /** @var callable */
    private $callback;

    /** @var callable|null */
    private $reverseCallback;

    public function __construct(callable $callback, ?callable $reverseCallback = null)
    {
        $this->callback = $callback;
        $this->reverseCallback = $reverseCallback;
    }

    public function stateForwards(ProjectState $state): void
    {
        // Data migrations usually don't affect the schema state
    }

    public function databaseForwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        ($this->callback)($schemaEditor, $toState, $registry);
    }

    public function databaseBackwards(SchemaEditor $schemaEditor, ProjectState $fromState, ProjectState $toState, StateRegistry $registry): void
    {
        if ($this->reverseCallback) {
            ($this->reverseCallback)($schemaEditor, $fromState, $registry);
        }
    }

    public function describe(): string
    {
        return "Run custom PHP logic";
    }

    public function reversible(): bool
    {
        return $this->reverseCallback !== null;
    }

    public function deconstruct(): array
    {
        $callback = $this->serializeCallable($this->callback);
        $reverseCallback = $this->reverseCallback ? $this->serializeCallable($this->reverseCallback) : null;

        return [
            get_class($this),
            [
                $callback,
                $reverseCallback,
            ],
        ];
    }

    private function serializeCallable(callable $callable): mixed
    {
        if (is_string($callable)) {
            return $callable;
        }

        if (is_array($callable) && count($callable) === 2 && is_string($callable[0]) && is_string($callable[1])) {
            return $callable;
        }

        throw new \RuntimeException(
            'RunPHP only supports serializable callables when generating migrations. ' .
            'Use a named function or [ClassName::class, "method"], not a closure or bound object method.'
        );
    }
}
