<?php

namespace Nitro\Events;

use Nitro\Events\Contracts\Dispatcher as DispatcherContract;
use Nitro\Events\Contracts\TogglesEvents;

/**
 * Event dispatcher — the app's one pub/sub bus (model events route through it too).
 *
 * Listeners receive the event payload as their single argument: a data array for
 * string events, or the event object itself for object events. dispatch() returns
 * the list of listener responses; until() stops at the first non-null response and
 * returns it (this is how a model "*ing" event vetoes an operation). A listener
 * returning false halts propagation to the remaining listeners.
 *
 *   $events->listen(OrderPaid::class, SendReceipt::class);  // class listener
 *   $events->listen('model.saved: App\Models\User', fn ($user) => ...);
 *   $events->listen('report.*', fn ($data) => ...);        // wildcard pattern
 *   $events->dispatch(new UserRegistered($user));          // object event
 *   $veto = $events->until('model.creating: App\Models\User', $user);
 *
 * A listener named by class is resolved from the container when the event
 * fires, not when it is registered — so registering a few hundred of them at
 * boot costs a few hundred array writes and constructs nothing. If that class
 * implements ShouldQueue it is pushed to the queue instead of being called, and
 * the code that raised the event is none the wiser.
 *
 * This is the bundled implementation, not the framework's dependency: the rest
 * of Nitro holds a {@see DispatcherContract}, so an application can bind its
 * own bus and nothing above has to change.
 */
class Dispatcher implements DispatcherContract, TogglesEvents
{
    /** Exact event name => list of listeners. @var array<string, list<callable|string>> */
    private array $listeners = [];

    /** Wildcard pattern (contains *) => list of listeners. @var array<string, list<callable|string>> */
    private array $wildcards = [];

    private bool $enabled = true;

    /**
     * The container used to resolve class listeners, and the queue they are
     * pushed to. Null outside an application (a bare `new Dispatcher` in a
     * unit test), where a class listener is constructed directly instead.
     */
    private ?object $container = null;

    public function setContainer(?object $container): void
    {
        $this->container = $container;
    }

    /**
     * Register a listener for one or more events. A pattern containing '*' (e.g.
     * 'model.*' or '*') is a wildcard that matches by glob.
     *
     * The listener is a callable, or the class name of a listener with a
     * handle() method — 'App\Listeners\SendReceipt', or the same with an
     * explicit method as 'App\Listeners\SendReceipt@notify'.
     *
     * @param string|string[] $events
     */
    public function listen(string|array $events, callable|string $listener): void
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
     * Register a subscriber: one class that declares its own listeners.
     *
     * The subscriber's subscribe() receives the dispatcher and registers what it
     * wants. It keeps the listener map next to the code it belongs to, rather
     * than in one provider that every feature has to edit.
     */
    public function subscribe(string|object $subscriber): void
    {
        $instance = is_string($subscriber) ? $this->resolve($subscriber) : $subscriber;

        $instance->subscribe($this);
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
            $response = $this->callListener($listener, $payload);

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

    /**
     * Invoke one listener.
     *
     * A callable is called. A class name is resolved and its handle() called —
     * unless it implements ShouldQueue, in which case the work is pushed to the
     * queue and the listener returns null, which reads to dispatch() as "no
     * opinion" and neither halts nor vetoes. A queued listener must not be able
     * to veto anything: the decision would be made after the fact, in another
     * process, with the request long since answered.
     */
    private function callListener(callable|string $listener, mixed $payload): mixed
    {
        if (!is_string($listener) || is_callable($listener)) {
            return $listener($payload);
        }

        [$class, $method] = $this->parseClassListener($listener);

        if (is_subclass_of($class, \Nitro\Queue\Contracts\ShouldQueue::class)) {
            $this->queueListener($class, $method, $payload);

            return null;
        }

        return $this->resolve($class)->{$method}($payload);
    }

    /**
     * Push a queued listener's work onto the queue.
     *
     * Outside an application there is no queue to push to, so the listener runs
     * inline. That keeps a unit test that dispatches an event from silently
     * doing nothing, which is a worse outcome than running the work in-process.
     */
    private function queueListener(string $class, string $method, mixed $payload): void
    {
        if ($this->container === null) {
            $this->resolve($class)->{$method}($payload);

            return;
        }

        CallQueuedListener::dispatch($class, $method, $payload);
    }

    /**
     * Split 'App\Listeners\SendReceipt@notify' into its class and method,
     * defaulting to handle().
     *
     * @return array{0: string, 1: string}
     */
    private function parseClassListener(string $listener): array
    {
        if (str_contains($listener, '@')) {
            return explode('@', $listener, 2);
        }

        // A single-purpose listener is often just __invoke().
        if (!method_exists($listener, 'handle') && method_exists($listener, '__invoke')) {
            return [$listener, '__invoke'];
        }

        return [$listener, 'handle'];
    }

    /** Resolve a listener class through the container when there is one. */
    private function resolve(string $class): object
    {
        if ($this->container !== null && method_exists($this->container, 'resolve')) {
            return $this->container->resolve($class);
        }

        return new $class;
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
