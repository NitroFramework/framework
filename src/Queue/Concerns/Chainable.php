<?php

namespace Nitro\Queue\Concerns;

use Nitro\Container\Contracts\ClassResolver;
use Nitro\Queue\Job;
use Throwable;

/**
 * Lets a job carry the jobs that should run after it.
 *
 *     Queue::chain([new PullOrders(), new Reconcile(), new Notify()])->dispatch();
 *
 * Only the first job is queued. It carries the rest with it, serialized, and
 * queues the next one itself once it has finished — so the second job does not
 * exist on any queue until the first has actually succeeded. That is the
 * difference from a batch, where everything is queued at once and order is
 * whatever the workers get to first.
 *
 * A job that fails ends the chain. The jobs behind it are never queued, which
 * is the point of asking for a chain rather than three dispatches.
 */
trait Chainable
{
    /**
     * The rest of the chain, serialized, in the order it should run.
     *
     * Serialized rather than held as objects because the chain travels inside
     * this job's own payload, and a job that is only going to be queued later
     * has no business being constructed now.
     *
     * @var array<int, string>
     */
    public array $chained = [];

    /** The connection the rest of the chain goes to, if not the default. */
    public ?string $chainConnection = null;

    /** The queue the rest of the chain goes to, if not the job's own. */
    public ?string $chainQueue = null;

    /**
     * What to run if any job in the chain fails.
     *
     * A class name or a [class, method] pair, matching a batch's callbacks —
     * a closure cannot survive being stored and read back by another process,
     * so one is refused rather than silently dropped.
     *
     * @var array<int, string|array{0: string, 1: string}>
     */
    public array $chainCatchCallbacks = [];

    /**
     * Put jobs behind this one.
     *
     * @param array<int, Job> $jobs
     */
    public function chain(array $jobs): static
    {
        $this->chained = array_map(
            static fn (Job $job): string => serialize($job),
            array_values($jobs),
        );

        return $this;
    }

    /** Send the rest of the chain to a particular connection. */
    public function onChainConnection(?string $connection): static
    {
        $this->chainConnection = $connection;

        return $this;
    }

    /** Send the rest of the chain to a particular queue. */
    public function onChainQueue(?string $queue): static
    {
        $this->chainQueue = $queue;

        return $this;
    }

    /**
     * What to run if the chain breaks.
     *
     * @param array<int, string|array{0: string, 1: string}> $callbacks
     */
    public function withChainCatchCallbacks(array $callbacks): static
    {
        $this->chainCatchCallbacks = $callbacks;

        return $this;
    }

    /**
     * Queue the next job in the chain, if there is one.
     *
     * Called by the worker once this job has succeeded. The remaining chain
     * moves onto the job being queued, so each job only ever carries what is
     * still to come.
     */
    public function dispatchNextJobInChain(): void
    {
        if ($this->chained === []) {
            return;
        }

        $chained = $this->chained;

        $next = unserialize(array_shift($chained));

        if (! $next instanceof Job) {
            return;
        }

        $next->chained = $chained;
        $next->chainConnection = $this->chainConnection;
        $next->chainQueue = $this->chainQueue;
        $next->chainCatchCallbacks = $this->chainCatchCallbacks;

        app(\Nitro\Queue\QueueManager::class)->dispatch(
            $next,
            $this->chainQueue ?? $next->queueName(),
            $this->chainConnection ?? $next->connectionName(),
        );
    }

    /**
     * Run what the chain asked for when one of its jobs failed.
     *
     * Called by the worker when a job is failed for good, so the jobs that
     * will now never run are accounted for somewhere.
     */
    public function invokeChainCatchCallbacks(Throwable $exception): void
    {
        if ($this->chainCatchCallbacks === []) {
            return;
        }

        $container = app();

        if (! $container->has(ClassResolver::class)) {
            return;
        }

        $resolver = $container->resolve(ClassResolver::class);

        foreach ($this->chainCatchCallbacks as $callback) {
            [$class, $method] = is_array($callback) ? $callback : [$callback, '__invoke'];

            $handler = $resolver->resolve($class);

            if (method_exists($handler, $method)) {
                $handler->{$method}($exception);
            }
        }
    }
}
