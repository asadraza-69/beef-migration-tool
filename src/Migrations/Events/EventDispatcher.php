<?php

namespace Nudelsalat\Migrations\Events;

class EventDispatcher
{
    private array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $name, object $event): void
    {
        if (!isset($this->listeners[$name])) {
            return;
        }

        foreach ($this->listeners[$name] as $listener) {
            $listener($event);
        }
    }
}
