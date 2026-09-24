<?php

namespace Nitro\Queue;

use Closure;
use InvalidArgumentException;

/**
 * Collects jobs that should run one after another, then dispatches the first.
 *
 *     Queue::chain([new PullOrders(), new Reconcile(), new Notify()])
 *         ->onQueue('nightly')
 *         ->catch(AlertOpsTeam::class)
 *         ->dispatch();
 *
 * Only the first job reaches a queue. It carries the rest and queues the next
 * itself once it has finished, so a job that fails takes the remainder of the
 * chain with it.
 */
class PendingChain
{
    /** @var array<int, Job> */
    protected array $jobs;

    protected ?string $connection = null;

    protected ?string $queue = null;

    protected int $delay = 0;

    /** @var array<int, string|array{0: string, 1: string}> */
    protected array $catchCallbacks = [];

    /**
     * @param array<int, Job> $jobs
     *
     * @throws InvalidArgumentException When the chain holds anything but jobs.
     */
    public function __construct(
        protected QueueManager $queue_,
        array $jobs,
    ) {
        foreach ($jobs as $job) {
            if (! $job instanceof Job) {
                throw new InvalidArgumentException(
                    'A chain takes jobs; got ' . get_debug_type($job) . '.'
                );
            }
        }

        $this->jobs = array_values($jobs);
    }

    /** Run the whole chain on a named connection. */
    public function onConnection(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /** Run the whole chain on a named queue. */
    public function onQueue(?string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    /** Hold the first job back; the rest follow as each one finishes. */
    public function delay(int $seconds): static
    {
        $this->delay = max(0, $seconds);

        return $this;
    }

    /**
     * What to run if any job in the chain fails.
     *
     * A class name or a [class, method] pair. A closure is refused because the
     * callback travels inside the job's payload and has to be read back by
     * whichever worker fails it.
     *
     * @param string|array{0: string, 1: string}|Closure $callback
     *
     * @throws InvalidArgumentException When given a closure.
     */
    public function catch(string|array|Closure $callback): static
    {
        if ($callback instanceof Closure) {
            throw new InvalidArgumentException(
                'A chain catch callback must be a class name or a [class, method] pair, '
                . 'because it is stored with the job and read back by another process.'
            );
        }

        $this->catchCallbacks[] = $callback;

        return $this;
    }

    /** The jobs, in the order they will run. */
    public function jobs(): array
    {
        return $this->jobs;
    }

    /**
     * Queue the first job, carrying the rest.
     *
     * @return int|string|null The first job's id, or null for an empty chain.
     */
    public function dispatch(): int|string|null
    {
        if ($this->jobs === []) {
            return null;
        }

        $jobs = $this->jobs;
        $first = array_shift($jobs);

        $first->chain($jobs);
        $first->onChainConnection($this->connection);
        $first->onChainQueue($this->queue);
        $first->withChainCatchCallbacks($this->catchCallbacks);

        return $this->queue_->dispatch(
            $first,
            $this->queue ?? $first->queueName(),
            $this->connection ?? $first->connectionName(),
            $this->delay,
        );
    }

    /** Dispatch only when the condition holds. */
    public function dispatchIf(mixed $condition): int|string|null
    {
        $condition = $condition instanceof Closure ? $condition($this) : $condition;

        return $condition ? $this->dispatch() : null;
    }

    /** Dispatch unless the condition holds. */
    public function dispatchUnless(mixed $condition): int|string|null
    {
        $condition = $condition instanceof Closure ? $condition($this) : $condition;

        return $condition ? null : $this->dispatch();
    }
}
