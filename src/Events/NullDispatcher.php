<?php

namespace Nitro\Events;

use Nitro\Events\Contracts\Dispatcher as DispatcherContract;

/**
 * A dispatcher that registers listeners and then never calls them.
 *
 * For running something with its side effects off — importing a
 * thousand records without sending a thousand notifications.
 * Registration is forwarded, so a listener registered while muted is
 * there when the real dispatcher is back in use.
 */
class NullDispatcher implements DispatcherContract
{
    public function __construct(protected DispatcherContract $dispatcher) {}

    /** The dispatcher this one is standing in for. */
    public function getDispatcher(): DispatcherContract
    {
        return $this->dispatcher;
    }

    public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): mixed
    {
        return null;
    }

    public function until(string|object $event, mixed $payload = []): mixed
    {
        return null;
    }

    public function push(string $event, mixed $payload = []): void
    {
        //
    }

    public function listen(string|array|\Closure|QueuedClosure $events, callable|string|array|null $listener = null): void
    {
        $this->dispatcher->listen($events, $listener);
    }

    public function subscribe(string|object $subscriber): void
    {
        $this->dispatcher->subscribe($subscriber);
    }

    public function hasListeners(string $event): bool
    {
        return $this->dispatcher->hasListeners($event);
    }

    public function forget(string $event): void
    {
        $this->dispatcher->forget($event);
    }

    public function flush(string $event): void
    {
        $this->dispatcher->flush($event);
    }

    public function forgetPushed(): void
    {
        $this->dispatcher->forgetPushed();
    }

    /** Anything else is the real dispatcher's business. */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->dispatcher->{$method}(...$parameters);
    }
}
