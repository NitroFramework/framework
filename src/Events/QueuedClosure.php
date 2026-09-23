<?php

namespace Nitro\Events;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * A closure listener that runs on the queue.
 *
 *     $events->listen(queueable(function (OrderPaid $event) {
 *         Receipt::for($event->orderId)->send();
 *     })->onQueue('mail'));
 *
 * The event class is read from the closure's type hint. What it
 * captures travels with it, so capture ids, not models.
 */
class QueuedClosure
{
    public ?string $connection = null;

    public ?string $queue = null;

    public int $delay = 0;

    /** @var array<int, Closure> Run if the listener fails. */
    public array $catchCallbacks = [];

    public function __construct(public Closure $closure) {}

    public function onConnection(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function onQueue(?string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    public function delay(int $delay): static
    {
        $this->delay = max(0, $delay);

        return $this;
    }

    /** Run a callback if the queued closure fails. */
    public function catch(Closure $closure): static
    {
        $this->catchCallbacks[] = $closure;

        return $this;
    }

    /**
     * The listener the dispatcher actually registers.
     *
     * Queues the real closure when the event fires, so nothing is
     * serialized until there is something to serialize.
     */
    public function resolve(Dispatcher $dispatcher): Closure
    {
        return function (...$arguments) use ($dispatcher): void {
            $dispatcher->pushJob(
                new InvokeQueuedClosure(
                    new SerializableClosure($this->closure),
                    $arguments,
                    array_map(
                        static fn (Closure $callback): SerializableClosure => new SerializableClosure($callback),
                        $this->catchCallbacks,
                    ),
                ),
                $this->connection,
                $this->queue,
                $this->delay,
            );
        };
    }
}
