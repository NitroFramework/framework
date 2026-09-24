<?php

namespace Nitro\Events;

use Closure;
use Nitro\Broadcasting\Contracts\ShouldBroadcast;
use Nitro\Events\Contracts\Dispatcher as DispatcherContract;
use Nitro\Events\Contracts\ShouldDispatchAfterCommit;
use Nitro\Events\Contracts\ShouldHandleEventsAfterCommit;
use Nitro\Events\Contracts\TogglesEvents;
use Nitro\Support\Arr;
use Nitro\Support\Str;
use ReflectionFunction;
use ReflectionNamedType;

/**
 * Event dispatcher — the app's one pub/sub bus (model events route through it too).
 *
 * A listener is called with the payload spread as its arguments, so
 * dispatch('order.paid', [$order, $amount]) reaches fn ($order, $amount).
 * An object event is its own payload and its class name is the event, so
 * fn (OrderPaid $event) is the usual shape. dispatch() returns the list of
 * listener responses; until() stops at the first non-null response and returns
 * it (this is how a model "*ing" event vetoes an operation). A listener
 * returning false halts propagation to the remaining listeners.
 *
 *   $events->listen(OrderPaid::class, SendReceipt::class);  // class listener
 *   $events->listen(fn (OrderPaid $e) => ...);              // event from the hint
 *   $events->listen(Billable::class, fn ($e) => ...);       // every implementor
 *   $events->listen('model.saved: App\Models\User', fn ($user) => ...);
 *   $events->listen('report.*', fn ($event, $payload) => ...);  // name, then payload
 *   $events->dispatch(new UserRegistered($user));           // object event
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
    /** Exact event name => list of listeners. @var array<string, list<callable|string|array>> */
    private array $listeners = [];

    /** Wildcard pattern (contains *) => list of listeners. @var array<string, list<callable|string|array>> */
    private array $wildcards = [];

    /**
     * Wildcard listeners already matched to an event name.
     *
     * @var array<string, list<Closure>>
     */
    private array $wildcardsCache = [];

    private bool $enabled = true;

    /** Events held back by defer(), to be dispatched when it returns. */
    private array $deferredEvents = [];

    private bool $deferringEvents = false;

    /** @var array<int, string>|null Which events defer() holds; null is all. */
    private ?array $eventsToDefer = null;

    /**
     * The container used to resolve class listeners, and the queue they are
     * pushed to. Null outside an application (a bare `new Dispatcher` in a
     * unit test), where a class listener is constructed directly instead.
     */
    private ?object $container = null;

    /** Where a queued listener is pushed; null uses the application's queue. */
    private ?Closure $queueResolver = null;

    /** Where after-commit listeners register; null dispatches immediately. */
    private ?Closure $transactionManagerResolver = null;

    /** What sends an event to listening clients; null broadcasts nothing. */
    private ?Closure $broadcastResolver = null;

    public function setContainer(?object $container): void
    {
        $this->container = $container;
    }

    /**
     * Name where queued listeners are pushed.
     */
    public function setQueueResolver(callable $resolver): static
    {
        $this->queueResolver = $resolver(...);

        return $this;
    }

    /**
     * Name what knows whether a database transaction is open.
     *
     * Without one an after-commit event dispatches immediately.
     */
    public function setTransactionManagerResolver(?callable $resolver): static
    {
        $this->transactionManagerResolver = $resolver === null ? null : $resolver(...);

        return $this;
    }

    /**
     * Name what sends an event on to listening clients.
     *
     * An event implementing {@see ShouldBroadcast} says it should reach the
     * browser as well as the application's own listeners. Nothing read that
     * before: the contract marked events and no dispatch path acted on the
     * mark, so broadcastOn() was never called and the event went nowhere.
     *
     * Resolved lazily and left null when nothing is bound, so an application
     * that does not broadcast pays nothing and an event still reaches its
     * listeners.
     */
    public function setBroadcastResolver(?callable $resolver): static
    {
        $this->broadcastResolver = $resolver === null ? null : $resolver(...);

        return $this;
    }

    /**
     * Register a listener for one or more events. A pattern containing '*' (e.g.
     * 'model.*' or '*') is a wildcard that matches by glob.
     *
     * The listener is a callable, or the class name of a listener with a
     * handle() method — 'App\Listeners\SendReceipt', or the same with an
     * explicit method as 'App\Listeners\SendReceipt@notify'.
     *
     * A closure on its own names its events by type hint.
     *
     * @param string|string[]|Closure|QueuedClosure $events
     */
    public function listen(string|array|Closure|QueuedClosure $events, callable|string|array|null $listener = null): void
    {
        if ($events instanceof QueuedClosure) {
            $listener = $events->resolve($this);
            $events = $this->eventsFromClosure($events->closure);
        } elseif ($events instanceof Closure) {
            $listener = $events;
            $events = $this->eventsFromClosure($events);
        } elseif ($listener instanceof QueuedClosure) {
            $listener = $listener->resolve($this);
        }

        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                $this->wildcards[$event][] = $listener;
                $this->wildcardsCache = [];
            } else {
                $this->listeners[$event][] = $listener;
            }
        }
    }

    /**
     * The event names a closure's first parameter type hint names.
     *
     * @return array<int, string>
     */
    private function eventsFromClosure(Closure $closure): array
    {
        $parameters = (new ReflectionFunction($closure))->getParameters();

        if ($parameters === []) {
            return [];
        }

        $type = $parameters[0]->getType();

        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if ($type instanceof \ReflectionUnionType) {
            return array_values(array_filter(array_map(
                static fn ($each): ?string => $each instanceof ReflectionNamedType && ! $each->isBuiltin()
                    ? $each->getName()
                    : null,
                $type->getTypes(),
            )));
        }

        return [];
    }

    /**
     * Register a subscriber: one class that declares its own listeners.
     *
     * Its subscribe() either registers directly or returns a map of
     * event to listener, which is registered here instead.
     */
    public function subscribe(string|object $subscriber): void
    {
        $instance = is_string($subscriber) ? $this->resolve($subscriber) : $subscriber;

        $events = $instance->subscribe($this);

        if (! is_array($events)) {
            return;
        }

        foreach ($events as $event => $listeners) {
            foreach (Arr::wrap($listeners) as $listener) {
                $this->listen($event, is_string($listener) && method_exists($instance, $listener)
                    ? [$instance::class, $listener]
                    : $listener);
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

        $isObject = is_object($event);

        [$event, $payload] = $this->parseEventAndPayload($event, $payload);

        if ($this->shouldDeferEvent($event)) {
            $this->deferredEvents[] = [$event, $payload, $halt];

            return null;
        }

        // Sent to listening clients as well as to listeners, when the event
        // asks for it. Held with the listeners rather than done here, so an
        // event waiting on a commit broadcasts when it is committed — telling
        // a browser about a row that then rolled back is worse than silence.
        $broadcast = $isObject && $payload[0] instanceof ShouldBroadcast
            ? fn () => $this->broadcastEvent($payload[0])
            : null;

        // An event that belongs to a transaction waits for the commit.
        if ($isObject
            && $payload[0] instanceof ShouldDispatchAfterCommit
            && ($transactions = $this->resolveTransactionManager()) !== null) {
            $transactions->addCallback(
                function () use ($broadcast, $event, $payload, $halt) {
                    if ($broadcast !== null) {
                        $broadcast();
                    }

                    return $this->invokeListeners($event, $payload, $halt);
                }
            );

            return null;
        }

        if ($broadcast !== null) {
            $broadcast();
        }

        return $this->invokeListeners($event, $payload, $halt);
    }

    /**
     * Hand an event to whatever sends it to clients.
     *
     * A failure here must not take the event's listeners with it: the
     * application's own work is the part that has to happen, and a broadcast
     * that cannot reach its driver is worth logging and carrying on from.
     */
    private function broadcastEvent(ShouldBroadcast $event): void
    {
        if ($this->broadcastResolver === null) {
            return;
        }

        $broadcaster = ($this->broadcastResolver)();

        if ($broadcaster === null) {
            return;
        }

        try {
            $broadcaster->queue($event);
        } catch (\Throwable $exception) {
            error_log('[broadcast] ' . $event::class . ': ' . $exception->getMessage());
        }
    }

    /**
     * An object event is its own payload; anything else is wrapped.
     *
     * The payload is spread when the listener is called, so a lone
     * object arrives as one argument and a list as several.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function parseEventAndPayload(string|object $event, mixed $payload): array
    {
        if (is_object($event)) {
            return [$event::class, [$event]];
        }

        return [$event, Arr::wrap($payload)];
    }

    /**
     * Call each listener in turn.
     *
     * @param array<int, mixed> $payload
     */
    private function invokeListeners(string $event, array $payload, bool $halt = false): mixed
    {
        $responses = [];

        foreach ($this->getListeners($event) as $listener) {
            $response = $listener($event, $payload);

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
     * Hold events raised inside the callback until it returns.
     *
     * Nothing is dispatched if the callback throws.
     *
     * @param array<int, string>|null $events Which events to hold; null is all.
     */
    public function defer(callable $callback, ?array $events = null): mixed
    {
        $wasDeferring = $this->deferringEvents;
        $previousEvents = $this->deferredEvents;
        $previousToDefer = $this->eventsToDefer;

        $this->deferringEvents = true;
        $this->deferredEvents = [];
        $this->eventsToDefer = $events;

        try {
            $result = $callback();

            $this->deferringEvents = false;

            foreach ($this->deferredEvents as [$event, $payload, $halt]) {
                $this->invokeListeners($event, $payload, $halt);
            }

            return $result;
        } finally {
            $this->deferringEvents = $wasDeferring;
            $this->deferredEvents = $previousEvents;
            $this->eventsToDefer = $previousToDefer;
        }
    }

    private function shouldDeferEvent(string $event): bool
    {
        return $this->deferringEvents
            && ($this->eventsToDefer === null || in_array($event, $this->eventsToDefer, true));
    }

    // ── Deferred (pushed) events ──────────────────────────────────────

    /**
     * Register an event to be dispatched later, by name.
     *
     * Nothing fires until flush() is called with the same name.
     */
    public function push(string $event, mixed $payload = []): void
    {
        $this->listen($event . '_pushed', function () use ($event, $payload): void {
            $this->dispatch($event, $payload);
        });
    }

    /** Dispatch what push() registered under this name. */
    public function flush(string $event): void
    {
        $this->dispatch($event . '_pushed');
    }

    /** Drop every pushed event without dispatching any of them. */
    public function forgetPushed(): void
    {
        foreach (array_keys($this->listeners) as $event) {
            if (str_ends_with($event, '_pushed')) {
                $this->forget($event);
            }
        }
    }

    // ── Reading the map ───────────────────────────────────────────────

    /**
     * Every listener for an event, ready to call.
     *
     * Each is wrapped to take ($event, $payload) whatever it was
     * registered as.
     *
     * @return array<int, Closure>
     */
    public function getListeners(string $event): array
    {
        $listeners = array_merge(
            $this->prepareListeners($event),
            $this->wildcardsCache[$event] ?? $this->getWildcardListeners($event),
        );

        return class_exists($event, false)
            ? $this->addInterfaceListeners($event, $listeners)
            : $listeners;
    }

    /**
     * The listener map as it was registered.
     *
     * @return array<string, list<callable|string|array>>
     */
    public function getRawListeners(): array
    {
        return $this->listeners;
    }

    /** @return array<int, Closure> */
    private function prepareListeners(string $event): array
    {
        $listeners = [];

        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listeners[] = $this->makeListener($listener);
        }

        return $listeners;
    }

    /** @return array<int, Closure> */
    private function getWildcardListeners(string $event): array
    {
        $wildcards = [];

        foreach ($this->wildcards as $pattern => $listeners) {
            if (Str::is($pattern, $event)) {
                foreach ($listeners as $listener) {
                    $wildcards[] = $this->makeListener($listener, true);
                }
            }
        }

        return $this->wildcardsCache[$event] = $wildcards;
    }

    /**
     * Listeners registered against an interface the event implements.
     *
     * One listener covers a family of events without naming each one.
     *
     * @param array<int, Closure> $listeners
     * @return array<int, Closure>
     */
    private function addInterfaceListeners(string $event, array $listeners = []): array
    {
        foreach (class_implements($event) ?: [] as $interface) {
            if (isset($this->listeners[$interface])) {
                $listeners = array_merge($listeners, $this->prepareListeners($interface));
            }
        }

        return $listeners;
    }

    /**
     * Wrap a listener so it can be called as ($event, $payload).
     *
     * A wildcard listener is given the event name first; anything else
     * takes the payload spread as its arguments.
     */
    public function makeListener(callable|string|array $listener, bool $wildcard = false): Closure
    {
        if (is_string($listener) || (is_array($listener) && isset($listener[0]) && is_string($listener[0]))) {
            return $this->createClassListener($listener, $wildcard);
        }

        return static function (string $event, array $payload) use ($listener, $wildcard): mixed {
            return $wildcard
                ? $listener($event, $payload)
                : $listener(...array_values($payload));
        };
    }

    /** The same, for a listener named by its class. */
    public function createClassListener(string|array $listener, bool $wildcard = false): Closure
    {
        return function (string $event, array $payload) use ($listener, $wildcard): mixed {
            $callable = $this->createClassCallable($listener);

            return $wildcard
                ? $callable($event, $payload)
                : $callable(...array_values($payload));
        };
    }

    /**
     * Resolve a class listener to something callable.
     *
     * A listener that should be queued is never constructed here.
     */
    private function createClassCallable(string|array $listener): callable
    {
        [$class, $method] = is_array($listener)
            ? $listener
            : $this->parseClassListener($listener);

        if ($this->handlerShouldBeQueued($class)) {
            return $this->createQueuedHandlerCallable($class, $method);
        }

        $instance = $this->resolve($class);

        // A "*ing" model event decides whether the operation happens, so it
        // cannot wait for the transaction it is inside.
        return $this->handlerShouldRunAfterCommit($instance)
            && ! in_array($method, ['creating', 'updating', 'saving', 'deleting', 'restoring'], true)
                ? $this->afterCommitCallable($instance, $method)
                : [$instance, $method];
    }

    private function handlerShouldBeQueued(string $class): bool
    {
        try {
            return (new \ReflectionClass($class))->implementsInterface(\Nitro\Queue\Contracts\ShouldQueue::class);
        } catch (\Throwable) {
            return false;
        }
    }

    private function handlerShouldRunAfterCommit(object $listener): bool
    {
        return (($listener->afterCommit ?? false) || $listener instanceof ShouldHandleEventsAfterCommit)
            && $this->resolveTransactionManager() !== null;
    }

    private function afterCommitCallable(object $listener, string $method): callable
    {
        return function (...$arguments) use ($listener, $method): void {
            $this->resolveTransactionManager()?->addCallback(
                static fn () => $listener->{$method}(...$arguments)
            );
        };
    }

    /**
     * The callable that puts a queued listener's work on the queue.
     *
     * The arguments are cloned, so a listener running later sees the
     * event as it was when raised.
     */
    private function createQueuedHandlerCallable(string $class, string $method): callable
    {
        return function (...$arguments) use ($class, $method): void {
            $arguments = array_map(
                static fn (mixed $argument): mixed => is_object($argument) ? clone $argument : $argument,
                $arguments,
            );

            if (! $this->handlerWantsToBeQueued($class, $arguments)) {
                return;
            }

            $this->queueHandler($class, $method, $arguments);
        };
    }

    /**
     * Whether a queued listener wants this particular event queued.
     *
     * shouldQueue() lets it decline one rather than spend a round trip
     * finding out there was nothing to do.
     *
     * @param array<int, mixed> $arguments
     */
    private function handlerWantsToBeQueued(string $class, array $arguments): bool
    {
        if (! method_exists($class, 'shouldQueue')) {
            return true;
        }

        return $this->resolve($class)->shouldQueue(...$arguments) !== false;
    }

    /**
     * Push a queued listener's work onto the queue.
     *
     * With no queue to push to the listener runs inline, rather than
     * the dispatch silently doing nothing.
     *
     * @param array<int, mixed> $arguments
     */
    private function queueHandler(string $class, string $method, array $arguments): void
    {
        $job = new CallQueuedListener($class, $method, $arguments[0] ?? null);

        if ($this->container === null && $this->queueResolver === null) {
            $this->resolve($class)->{$method}(...$arguments);

            return;
        }

        $listener = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        $job->propagateOptionsFrom($listener, $arguments);

        $this->pushJob($job, $job->connectionName(), $job->queueName(), $job->delay());
    }

    /**
     * Put a job on the queue this dispatcher was told to use.
     *
     * Every queued listener goes through here, class or closure, so a
     * swapped queue sees both.
     */
    public function pushJob(
        \Nitro\Queue\Job $job,
        ?string $connection = null,
        ?string $queue = 'default',
        int $delay = 0,
    ): void {
        $queue ??= 'default';
        $delay = max(0, $delay);

        $envelope = new \Nitro\Queue\QueuedJob(
            id: null,
            queue: $queue,
            payload: \Nitro\Queue\QueuedJob::encode($job),
            attempts: 0,
            availableAt: time() + $delay,
            reservedAt: null,
            createdAt: time(),
        );

        $connection = $this->resolveQueue()->connection($connection);

        $delay > 0
            ? $connection->laterOn($queue, $delay, $envelope)
            : $connection->pushOn($queue, $envelope);
    }

    private function resolveQueue(): \Nitro\Queue\QueueManager
    {
        if ($this->queueResolver !== null) {
            return ($this->queueResolver)();
        }

        return $this->resolve(\Nitro\Queue\QueueManager::class);
    }

    /** What knows whether a transaction is open, or null. */
    private function resolveTransactionManager(): ?object
    {
        return $this->transactionManagerResolver === null
            ? null
            : ($this->transactionManagerResolver)();
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
        if (! method_exists($listener, 'handle') && method_exists($listener, '__invoke')) {
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

    public function forget(string $event): void
    {
        if (str_contains($event, '*')) {
            unset($this->wildcards[$event]);

            $this->wildcardsCache = [];

            return;
        }

        unset($this->listeners[$event]);
    }

    public function hasListeners(string $event): bool
    {
        return isset($this->listeners[$event])
            || isset($this->wildcards[$event])
            || $this->hasWildcardListeners($event);
    }

    /** Whether any registered pattern matches this event name. */
    public function hasWildcardListeners(string $event): bool
    {
        foreach (array_keys($this->wildcards) as $pattern) {
            if (Str::is($pattern, $event)) {
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

    /** Drop every listener, of every kind. */
    public function forgetAll(): void
    {
        $this->listeners = [];
        $this->wildcards = [];
        $this->wildcardsCache = [];
    }
}
