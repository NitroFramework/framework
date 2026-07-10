<?php

namespace Nitro\Events;

/**
 * Event dispatcher — the app's one pub/sub bus (model events route through it too).
 *
 * Listeners receive the event payload as their single argument: a data array for
 * string events, or the event object itself for object events. dispatch() returns
 * the list of listener responses; until() stops at the first non-null response and
 * returns it (this is how a model "*ing" event vetoes an operation). A listener
 * returning false halts propagation to the remaining listeners.
 *
 *   $events->listen('model.saved: App\Models\User', fn ($user) => ...);
 *   $events->listen('report.*', fn ($data) => ...);        // wildcard pattern
 *   $events->dispatch(new UserRegistered($user));          // object event
 *   $veto = $events->until('model.creating: App\Models\User', $user);
 */
class Dispatcher
{
    /** Exact event name => list of listeners. @var array<string, list<callable>> */
    private array $listeners = [];

    /** Wildcard pattern (contains *) => list of listeners. @var array<string, list<callable>> */
    private array $wildcards = [];

    private bool $enabled = true;

    /**
     * Register a listener for one or more events. A pattern containing '*' (e.g.
     * 'model.*' or '*') is a wildcard that matches by glob.
     *
     * @param string|string[] $events
     */
    public function listen(string|array $events, callable $listener): void
    {
        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                $this->wildcards[$event][] = $listener;
            } else {
                $this->listeners[$event][] = $listener;
            }
        }
    }

    /**
     * Dispatch an event to its listeners.
     *
     * An object event uses its class name as the event and the object as the
     * payload. Returns the array of listener return values — or, when $halt is
     * true, the first non-null return value (or null if none).
     */
    public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): mixed
    {
        if (! $this->enabled) {
            return $halt ? null : [];
        }

        if (is_object($event)) {
            $payload = $event;
            $event   = $event::class;
        }

        $responses = [];

        foreach ($this->listenersFor($event) as $listener) {
            $response = $listener($payload);

            // Halting (until): the first non-null response wins and stops the chain.
            if ($halt && $response !== null) {
                return $response;
            }

            // A false response stops propagation to the remaining listeners.
            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    /** Dispatch and stop at the first non-null response, returning it. */
    public function until(string|object $event, mixed $payload = []): mixed
    {
        return $this->dispatch($event, $payload, true);
    }

    /** Exact listeners for $event, plus any whose wildcard pattern matches it. */
    private function listenersFor(string $event): array
    {
        $listeners = $this->listeners[$event] ?? [];

        foreach ($this->wildcards as $pattern => $callbacks) {
            if ($this->patternMatches($pattern, $event)) {
                $listeners = array_merge($listeners, $callbacks);
            }
        }

        return $listeners;
    }

    private function patternMatches(string $pattern, string $event): bool
    {
        if ($pattern === '*') {
            return true;
        }

        return (bool) preg_match('#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#', $event);
    }

    public function forget(string $event): void
    {
        unset($this->listeners[$event], $this->wildcards[$event]);
    }

    public function hasListeners(string $event): bool
    {
        if (! empty($this->listeners[$event])) {
            return true;
        }

        foreach (array_keys($this->wildcards) as $pattern) {
            if ($this->patternMatches($pattern, $event)) {
                return true;
            }
        }

        return false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function flush(): void
    {
        $this->listeners = [];
        $this->wildcards = [];
    }
}
