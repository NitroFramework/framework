<?php

namespace Nitro\Events;

use Nitro\Queue\Job;
use ReflectionClass;
use Throwable;

/**
 * The job that runs a queued event listener.
 *
 * A listener implementing ShouldQueue is not called when the event is
 * dispatched; this job is pushed instead, carrying the listener's class name
 * and the event itself. The worker resolves the listener from the container and
 * calls it, so the listener is written exactly as a synchronous one would be
 * and the only difference is where it runs.
 *
 * The event is serialized into the payload, which is the constraint worth
 * knowing: an event carrying a whole model graph, a closure or a PDO handle
 * will not survive the trip. Put ids on events, not objects — the listener
 * re-queries, and it gets current data rather than a snapshot from whenever the
 * job was pushed.
 *
 * The listener's own terms travel with it, read off the listener at
 * push time — the worker has a job, not a listener.
 */
class CallQueuedListener extends Job
{
    /** Middleware the listener asked its work to run through. */
    public array $middleware = [];

    /** Seconds before the job becomes eligible, from withDelay(). */
    private int $listenerDelay = 0;

    /** Set from the listener, overriding what this class declares. */
    private ?string $listenerQueue = null;

    private ?string $listenerConnection = null;

    private ?int $listenerRetryUntil = null;

    /**
     * @param  class-string  $listenerClass
     * @param  string        $method  Usually 'handle'.
     */
    public function __construct(
        public string $listenerClass,
        public string $method,
        public mixed $event,
    ) {}

    public function handle(): void
    {
        $listener = \app($this->listenerClass);

        $listener->{$this->method}($this->event);
    }

    /**
     * Read the listener's queue terms and carry them on the job.
     *
     * A method is asked before a property, and is given the event when
     * it takes one, so a listener can route by what happened.
     *
     * @param array<int, mixed> $arguments The event, as it was dispatched.
     */
    public function propagateOptionsFrom(object $listener, array $arguments = []): void
    {
        $event = $arguments[0] ?? null;

        $this->listenerQueue = $this->ask($listener, 'viaQueue', 'queue', $event);
        $this->listenerConnection = $this->ask($listener, 'viaConnection', 'connection', $event);
        $this->listenerDelay = max(0, (int) $this->ask($listener, 'withDelay', 'delay', $event));

        $tries = $this->ask($listener, 'tries', 'tries', $event);

        if ($tries !== null) {
            $this->tries = (int) $tries;
        }

        $backoff = $this->ask($listener, 'backoff', 'backoff', $event);

        if (is_int($backoff)) {
            $this->backoff = $backoff;
        }

        $timeout = $this->ask($listener, 'timeout', 'timeout', $event);

        if ($timeout !== null) {
            $this->timeout = (int) $timeout;
        }

        $retryUntil = $this->ask($listener, 'retryUntil', 'retryUntil', $event);

        $this->listenerRetryUntil = $retryUntil instanceof \DateTimeInterface
            ? $retryUntil->getTimestamp()
            : ($retryUntil === null ? null : (int) $retryUntil);

        $this->middleware = array_merge(
            (array) ($this->ask($listener, 'middleware', 'middleware', $event) ?? []),
        );
    }

    /**
     * What the listener says about one term, by method or by property.
     *
     * A method taking no parameter is called without the event.
     */
    private function ask(object $listener, string $method, string $property, mixed $event): mixed
    {
        if (method_exists($listener, $method)) {
            $reflector = new \ReflectionMethod($listener, $method);

            return $reflector->getNumberOfParameters() > 0 && $event !== null
                ? $listener->{$method}($event)
                : $listener->{$method}();
        }

        if (property_exists($listener, $property)) {
            return (new ReflectionClass($listener))->getDefaultProperties()[$property] ?? null;
        }

        return null;
    }

    /**
     * Route to the queue the listener asks for, so a slow listener can be kept
     * off the queue that sends password resets.
     */
    public function queueName(): string
    {
        return $this->listenerQueue ?? 'default';
    }

    public function connectionName(): ?string
    {
        return $this->listenerConnection;
    }

    /** Seconds the listener asked to wait before running. */
    public function delay(): int
    {
        return $this->listenerDelay;
    }

    public function retryUntil(): ?int
    {
        return $this->listenerRetryUntil;
    }

    /**
     * Tell the listener its work failed.
     */
    public function failed(Throwable $exception): void
    {
        $listener = \app($this->listenerClass);

        if (method_exists($listener, 'failed')) {
            $listener->failed($this->event, $exception);
        }
    }

    /**
     * Name the listener in the failed-jobs table.
     */
    public function displayName(): string
    {
        return $this->listenerClass;
    }
}
