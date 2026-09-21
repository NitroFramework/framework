<?php

namespace Nitro\Queue\Batching;

use Closure;
use InvalidArgumentException;
use Nitro\Http\Kernel;
use Nitro\Queue\Batchable;
use Nitro\Queue\Job;
use Nitro\Queue\QueueManager;

/**
 * Collects jobs and the callbacks to run as they settle, then dispatches them
 * as one batch.
 *
 *     Queue::batch([new ImportRows(1), new ImportRows(2)])
 *         ->name('nightly import')
 *         ->then(NotifyImportFinished::class)
 *         ->catch(AlertImportFailed::class)
 *         ->dispatch();
 *
 * A callback is a class name or a [class, method] pair. It has to survive
 * being stored and read back by whichever worker settles the last job, and a
 * closure cannot be, so one is refused rather than silently dropped.
 */
class PendingBatch
{
    /** @var array<int, Job> */
    protected array $jobs = [];

    protected string $name = '';

    /** @var array<string, mixed> */
    protected array $options = [];

    /**
     * @param array<int, Job>|Job $jobs
     */
    public function __construct(
        protected QueueManager $queue,
        protected BatchRepository $repository,
        protected BatchCallbacks $callbacks,
        array|Job $jobs = [],
        protected ?Kernel $kernel = null,
    ) {
        $this->add($jobs);
    }

    /**
     * Add jobs to the batch.
     *
     * @param  array<int, Job>|Job $jobs
     * @throws InvalidArgumentException When a job cannot carry a batch id.
     */
    public function add(array|Job $jobs): static
    {
        foreach (is_array($jobs) ? $jobs : [$jobs] as $job) {
            if (! in_array(Batchable::class, class_uses_recursive($job), true)) {
                throw new InvalidArgumentException(
                    $job::class . ' must use the Batchable trait to be dispatched in a batch.'
                );
            }

            $this->jobs[] = $job;
        }

        return $this;
    }

    // ─── Callbacks ────────────────────────────────────────

    /** Run before the first job is dispatched. */
    public function before(string|array|Closure $callback): static
    {
        return $this->addCallback('before', $callback);
    }

    /** @return array<int, string|array{0: string, 1: string}> */
    public function beforeCallbacks(): array
    {
        return $this->options['before'] ?? [];
    }

    /** Run each time a job in the batch settles. */
    public function progress(string|array|Closure $callback): static
    {
        return $this->addCallback('progress', $callback);
    }

    /** @return array<int, string|array{0: string, 1: string}> */
    public function progressCallbacks(): array
    {
        return $this->options['progress'] ?? [];
    }

    /** Run when every job has succeeded. */
    public function then(string|array|Closure $callback): static
    {
        return $this->addCallback('then', $callback);
    }

    /** @return array<int, string|array{0: string, 1: string}> */
    public function thenCallbacks(): array
    {
        return $this->options['then'] ?? [];
    }

    /** Run when a job in the batch fails. */
    public function catch(string|array|Closure $callback): static
    {
        return $this->addCallback('catch', $callback);
    }

    /** @return array<int, string|array{0: string, 1: string}> */
    public function catchCallbacks(): array
    {
        return $this->options['catch'] ?? [];
    }

    /** Run once the batch settles, whether or not anything failed. */
    public function finally(string|array|Closure $callback): static
    {
        return $this->addCallback('finally', $callback);
    }

    /** @return array<int, string|array{0: string, 1: string}> */
    public function finallyCallbacks(): array
    {
        return $this->options['finally'] ?? [];
    }

    /** @return array<int, string|array{0: string, 1: string}> */
    public function failureCallbacks(): array
    {
        return $this->catchCallbacks();
    }

    // ─── Options ──────────────────────────────────────────

    /** Let the rest of the batch continue after a job fails. */
    public function allowFailures(bool $allow = true): static
    {
        $this->options['allowFailures'] = $allow;

        return $this;
    }

    public function allowsFailures(): bool
    {
        return (bool) ($this->options['allowFailures'] ?? false);
    }

    public function name(?string $name = null): static|string
    {
        if ($name === null) {
            return $this->name;
        }

        $this->name = $name;

        return $this;
    }

    public function onConnection(string $connection): static
    {
        $this->options['connection'] = $connection;

        return $this;
    }

    public function connection(): ?string
    {
        return $this->options['connection'] ?? null;
    }

    public function onQueue(string $queue): static
    {
        $this->options['queue'] = $queue;

        return $this;
    }

    public function queue(): ?string
    {
        return $this->options['queue'] ?? null;
    }

    public function withOption(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return array<int, Job> */
    public function jobs(): array
    {
        return $this->jobs;
    }

    // ─── Dispatching ──────────────────────────────────────

    /** Record the batch, then push its jobs carrying the batch id. */
    public function dispatch(): Batch
    {
        $batch = $this->repository->store($this);

        $this->runBeforeCallbacks($batch);

        foreach ($this->jobs as $job) {
            $job->withBatchId($batch->id);

            $this->queue->push(
                $job,
                $this->queue() ?? $job->queueName(),
                $this->connection() ?? $job->connectionName(),
            );
        }

        return $batch->fresh() ?? $batch;
    }

    /** Dispatch only when the condition holds. */
    public function dispatchIf(mixed $condition): ?Batch
    {
        $condition = $condition instanceof Closure ? $condition($this) : $condition;

        return $condition ? $this->dispatch() : null;
    }

    /** Dispatch unless the condition holds. */
    public function dispatchUnless(mixed $condition): ?Batch
    {
        $condition = $condition instanceof Closure ? $condition($this) : $condition;

        return $condition ? null : $this->dispatch();
    }

    /**
     * Dispatch once the response has been sent.
     *
     * Falls back to dispatching now when there is no kernel to wait on, such
     * as in a console command.
     */
    public function dispatchAfterResponse(): void
    {
        if ($this->kernel === null) {
            $this->dispatch();

            return;
        }

        $this->kernel->terminating(function (): void {
            $this->dispatch();
        });
    }

    /**
     * @param  string|array{0: string, 1: string}|Closure $callback
     * @throws InvalidArgumentException When given a closure.
     */
    protected function addCallback(string $event, string|array|Closure $callback): static
    {
        if ($callback instanceof Closure) {
            throw new InvalidArgumentException(
                "A batch {$event}() callback must be a class name or [class, method] pair. "
                . 'A closure cannot be stored for the worker that settles the last job to read back.'
            );
        }

        $this->options[$event][] = $callback;

        return $this;
    }

    private function runBeforeCallbacks(Batch $batch): void
    {
        $this->callbacks->before($batch);
    }
}
