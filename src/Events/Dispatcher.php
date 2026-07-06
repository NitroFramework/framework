<?php

namespace Nitro\Events;

/**
 * Simple Event Dispatcher for NitroPHP
 *
 * Provides a lightweight event system for observing framework behavior.
 * Used primarily by debugging tools (like Telescope) and logging systems.
 */
class Dispatcher
{
    private array $listeners = [];
    private array $wildcardListeners = [];
    private bool $enabled = true;

    public function listen(string $event, callable $listener): void
    {
        if ($event === '*') {
            $this->wildcardListeners[] = $listener;
            return;
        }

        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, array $data = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $data['_event'] = $event;

        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($data);
        }

        foreach ($this->wildcardListeners as $listener) {
            $listener($data);
        }
    }

    public function forget(string $event): void
    {
        // Wildcard listeners live in a separate bag, so forget('*') has to clear
        // that — unsetting $listeners['*'] alone left them registered.
        if ($event === '*') {
            $this->wildcardListeners = [];
            return;
        }

        unset($this->listeners[$event]);
    }

    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]) || !empty($this->wildcardListeners);
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function flush(): void
    {
        $this->listeners = [];
        $this->wildcardListeners = [];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
