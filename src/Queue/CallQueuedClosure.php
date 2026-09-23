<?php

namespace Nitro\Queue;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use Nitro\Container\Contracts\CallableInvoker;
use ReflectionFunction;
use Throwable;

/**
 * Queues a closure instead of a job class.
 *
 *     CallQueuedClosure::create(function () {
 *         Report::rebuild();
 *     })->onQueue('reports');
 *
 * What the closure captures is serialized with it, so the rule for a
 * job's constructor arguments applies — capture ids, not models.
 */
class CallQueuedClosure extends Job
{
    use InteractsWithQueue;

    /** The closure, in a form that survives serialization. */
    public SerializableClosure $closure;

    /** A name for this job in logs and the failed store. */
    public ?string $name = null;

    /** @var array<int, SerializableClosure|callable> Run if the job fails. */
    public array $failureCallbacks = [];

    public function __construct(SerializableClosure $closure)
    {
        $this->closure = $closure;
    }

    public static function create(Closure $job): static
    {
        return new static(new SerializableClosure($job));
    }

    /** Queue a closure, returning the builder for queue and delay. */
    public static function dispatchClosure(Closure $job): PendingDispatch
    {
        return new PendingDispatch(static::create($job));
    }

    /**
     * Run the closure, resolving whatever it type-hints.
     *
     * The job is offered as $job, so the closure can release or delete
     * its own place in the queue.
     */
    public function handle(): void
    {
        $closure = $this->closure->getClosure();

        try {
            $invoker = \app(CallableInvoker::class);
        } catch (Throwable) {
            $closure($this);

            return;
        }

        $invoker->call($closure, ['job' => $this]);
    }

    /** Run a callback if this job fails. */
    public function onFailure(callable $callback): static
    {
        $this->failureCallbacks[] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;

        return $this;
    }

    public function failed(Throwable $exception): void
    {
        foreach ($this->failureCallbacks as $callback) {
            $callback = $callback instanceof SerializableClosure
                ? $callback->getClosure()
                : $callback;

            $callback($exception);
        }
    }

    /** Name the job, so the failed store says more than "Closure". */
    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * What this job is called, in a log or the failed store.
     *
     * A closure has no class name, so it is named by where it was
     * written.
     */
    public function displayName(): string
    {
        $reflection = new ReflectionFunction($this->closure->getClosure());

        $prefix = $this->name === null ? '' : $this->name . ' - ';

        return $prefix . 'Closure (' . basename((string) $reflection->getFileName())
            . ':' . $reflection->getStartLine() . ')';
    }
}
