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
 * Worth having for work that needs no arguments and belongs to one
 * call site: a class for it says nothing the closure does not, and
 * lives on afterwards as something to maintain.
 *
 * What the closure captures is serialized with it, so the same rule
 * applies as to a job's constructor arguments — capture ids, not
 * models, and re-read inside. A closure that captures $this, a
 * resource or a database connection cannot be queued at all.
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
     * The job itself is offered as $job so the closure can release or
     * delete its own place in the queue, the same as a job class can.
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
     * What this job is called.
     *
     * A closure has no class name to fall back on, so the file and
     * line it was written at is what identifies it in a log.
     */
    public function displayName(): string
    {
        $reflection = new ReflectionFunction($this->closure->getClosure());

        $prefix = $this->name === null ? '' : $this->name . ' - ';

        return $prefix . 'Closure (' . basename((string) $reflection->getFileName())
            . ':' . $reflection->getStartLine() . ')';
    }
}
