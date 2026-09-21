<?php

namespace Nitro\Queue;

use Nitro\Cache\CacheManager;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Queue\Batching\Batch;
use Nitro\Queue\Batching\BatchCallbacks;
use Nitro\Queue\Batching\BatchRepository;
use Nitro\Queue\Contracts\FailedJobStore;
use Nitro\Queue\Contracts\Queue;
use Nitro\Queue\Contracts\ShouldBeUnique;
use Nitro\Queue\Contracts\ShouldBeUniqueUntilProcessing;
use Nitro\Queue\Events;
use Nitro\Support\Pipeline;
use Throwable;

/**
 * The long-running process that pulls jobs off a queue and runs them.
 *
 *   php nitro queue:work                  # forever loop, default options
 *   php nitro queue:work --once           # process one job, then exit
 *   php nitro queue:work --queue=mail     # specific queue
 *   php nitro queue:work --tries=5        # override default max attempts
 *
 * Lifecycle of one tick:
 *   1. queue.pop() — atomically reserve next runnable job (or null).
 *   2. If null: sleep $sleep seconds, loop.
 *   3. Decode the payload; if that throws, fail to the failed store.
 *   4. If attempts > tries: fail (poison-pill from a prior crash).
 *   5. Container-resolve handle() dependencies; call handle().
 *   6. Success → queue.delete(). Throw → release with backoff, or fail
 *      if this attempt blew the tries budget.
 *   7. Check stop conditions (signal, memory, restart cache key) and
 *      either continue or exit.
 *
 * Graceful shutdown: pcntl_signal traps SIGTERM/SIGINT and sets the
 * stop flag. The current job finishes; the loop exits before pulling
 * another. Worker supervisors (systemd, supervisord, Procfile, etc.)
 * can restart the process cleanly.
 *
 * Restart-on-deploy: queue:restart bumps a cache key. The worker reads
 * the key after each job and exits when it changes. Pairs with a
 * supervisor that auto-restarts — old code drains, new code starts.
 */
class Worker
{
    private bool $shouldStop = false;
    private ?int $cachedRestart = null;

    public function __construct(
        private QueueManager $queues,
        private FailedJobStore $failedStore,
        private ClassResolver $resolver,
        private ?CacheManager $cache = null,
        private ?BatchRepository $batches = null,
        private ?BatchCallbacks $batchCallbacks = null,
        private ?UniqueLock $uniqueLock = null,
        private ?EventDispatcher $events = null,
    ) {
        $this->installSignalHandlers();
    }

    /**
     * @param  array{
     *   connection?: ?string,
     *   queue?: string,
     *   sleep?: int,
     *   tries?: ?int,
     *   maxJobs?: int,
     *   maxTime?: int,
     *   maxMemory?: int,
     *   once?: bool,
     *   onJob?: ?callable
     * } $options
     */
    public function run(array $options = []): int
    {
        $connection = $options['connection'] ?? null;
        $queueName  = $options['queue'] ?? 'default';
        $sleep      = max(0, $options['sleep'] ?? 1);
        $defaultTries = $options['tries'] ?? null;
        $maxJobs    = max(0, $options['maxJobs'] ?? 0);
        $maxTime    = max(0, $options['maxTime'] ?? 0);
        $maxMemory  = max(0, $options['maxMemory'] ?? 128);
        $once       = $options['once'] ?? false;
        $onJob      = $options['onJob'] ?? null;

        $queue = $this->queues->connection($connection);
        $this->cachedRestart = $this->readRestartSignal();

        $processed = 0;
        $startedAt = time();

        while (true) {
            if ($this->shouldStop || $this->restartSignalled()) {
                return 0;
            }

            $envelope = $queue->pop($queueName);
            if ($envelope === null) {
                if ($once) return 0;
                $this->stoppableSleep($sleep);
                continue;
            }

            $this->runJob($queue, $envelope, $defaultTries);
            $processed++;
            if (is_callable($onJob)) $onJob($envelope);

            if ($once) return 0;
            if ($maxJobs > 0 && $processed >= $maxJobs) return 0;
            if ($maxTime > 0 && (time() - $startedAt) >= $maxTime) return 0;
            if ($maxMemory > 0 && $this->memoryExceeded($maxMemory)) return 0;
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function runJob(Queue $queue, QueuedJob $envelope, ?int $defaultTries): void
    {
        $job = null;
        try {
            ['instance' => $job] = $envelope->decode();
            // Expose the reserved attempt count so handle()/backoff() can be
            // attempt-aware (the docblock's `2 ** $this->currentAttempts`).
            $job->setCurrentAttempts($envelope->attempts);

            // A job that interacts with the queue needs its envelope before
            // handle() can release, delete or fail it.
            if (method_exists($job, 'setJob')) {
                $job->setJob($envelope, $queue);
            }
            $tries = $defaultTries ?? $job->tries();

            // Poison-pill guard: a job that comes off the queue with
            // attempts already past the cap means a prior worker crashed
            // mid-handle and the reservation expired. We don't run
            // handle() again — straight to failed. tries <= 0 means "no limit"
            // (Laravel's --tries=0), so the guard only applies when tries > 0 —
            // otherwise every fresh job (attempts already 1 from pop) failed
            // immediately.
            if ($tries > 0 && $envelope->attempts > $tries) {
                throw new Exceptions\MaxAttemptsExceededException(
                    "Job exhausted retry budget ({$tries}) without success."
                );
            }

            $this->event(new Events\JobProcessing($envelope));

            if ($job instanceof ShouldBeUniqueUntilProcessing) {
                $this->releaseUniqueLock($job);
            }

            $this->invoke($job);
            $queue->delete($envelope);

            if ($job instanceof ShouldBeUnique) {
                $this->releaseUniqueLock($job);
            }

            $this->event(new Events\JobProcessed($envelope));
            $this->recordBatchSuccess($job, $envelope);
        } catch (Throwable $exception) {
            $this->handleFailure($queue, $envelope, $job, $exception, $defaultTries);
        }
    }

    /** Move a batch's counters and fire its callbacks after a job succeeds. */
    private function recordBatchSuccess(Job $job, QueuedJob $envelope): void
    {
        $batch = $this->batchOf($job);

        if ($batch === null) {
            return;
        }

        $batch->recordSuccessfulJob((string) $envelope->id);

        $this->fireBatchCallbacks($batch->fresh() ?? $batch);
    }

    /** The same, after a job has exhausted its attempts. */
    private function recordBatchFailure(Job $job, QueuedJob $envelope, Throwable $exception): void
    {
        $batch = $this->batchOf($job);

        if ($batch === null) {
            return;
        }

        // Cancelling when failures are not allowed happens inside the batch,
        // so the jobs still queued behind this one can see there is nothing
        // left to do.
        $batch->recordFailedJob((string) $envelope->id, $exception);

        $settled = $batch->fresh() ?? $batch;

        $this->batchCallbacks?->failed($settled, $exception);

        $this->fireBatchCallbacks($settled);
    }

    /** Run the progress callback, and the settling ones when it is over. */
    private function fireBatchCallbacks(Batch $batch): void
    {
        $this->batchCallbacks?->progress($batch);
        $this->batchCallbacks?->settled($batch);
    }

    /** Give back the claim that kept a unique job from being queued twice. */
    private function releaseUniqueLock(Job $job): void
    {
        if (! $job instanceof ShouldBeUnique || $this->uniqueLock === null) {
            return;
        }

        $this->uniqueLock->release($job);
    }

    /** Dispatch a lifecycle event, when anything is listening. */
    private function event(object $event): void
    {
        $this->events?->dispatch($event);
    }

    /** The batch a job belongs to, or null when it is not batched. */
    private function batchOf(Job $job): ?Batch
    {
        if (! property_exists($job, 'batchId') || $job->batchId === null) {
            return null;
        }

        return $this->batches?->find($job->batchId);
    }

    /**
     * Run a job through its own middleware, then call it.
     *
     * A job declares middleware with a middleware() method or a $middleware
     * property; without either this is the call on its own.
     */
    private function invoke(Job $job): void
    {
        $middleware = $this->middlewareFor($job);

        if ($middleware === []) {
            $this->call($job);

            return;
        }

        Pipeline::make($this->resolver)
            ->send($job)
            ->through($middleware)
            ->then(function (Job $job): void {
                $this->call($job);
            });
    }

    /**
     * The middleware a job asks to run through.
     *
     * @return array<int, mixed>
     */
    private function middlewareFor(Job $job): array
    {
        $declared = method_exists($job, 'middleware') ? $job->middleware() : [];
        $property = property_exists($job, 'middleware') ? $job->middleware : [];

        return array_merge((array) $declared, (array) $property);
    }

    private function call(Job $job): void
    {
        $reflector = new \ReflectionMethod($job, 'handle');
        $args = [];
        foreach ($reflector->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->resolver->resolve($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }
        $job->handle(...$args);
    }

    private function handleFailure(
        Queue $queue,
        QueuedJob $envelope,
        ?Job $job,
        Throwable $exception,
        ?int $defaultTries,
    ): void {
        $tries = $defaultTries ?? ($job?->tries() ?? 1);

        // A job we couldn't even decode (missing class / corrupt payload) can
        // never succeed on a retry — fail it immediately regardless of the
        // retry budget, rather than releasing it to loop forever.
        $cannotDecode = $job === null;
        $isPoisonPill = $exception instanceof Exceptions\MaxAttemptsExceededException;

        // Permanent failure: log to failed store, drop from live queue,
        // and let the job's failed() hook run for notifications/audit. The
        // attempts cap is only enforced when tries > 0 (0 = unlimited).
        if ($cannotDecode || $isPoisonPill || ($tries > 0 && $envelope->attempts >= $tries)) {
            $this->failedStore->log($envelope, $exception);
            $queue->delete($envelope);

            if ($job instanceof ShouldBeUnique) {
                $this->releaseUniqueLock($job);
            }

            $this->event(new Events\JobFailed($envelope, null, $exception));

            if ($job !== null) {
                try {
                    $job->failed($exception);
                } catch (Throwable $hookError) {
                    error_log('[queue] failed() hook threw: ' . $hookError->getMessage());
                }

                $this->recordBatchFailure($job, $envelope, $exception);
            }
            return;
        }

        // Retryable: record why (a flapping job was previously invisible until
        // its final failure), then release with backoff. attempts was already
        // incremented during pop(), so the next worker sees one more.
        error_log("[queue] job {$envelope->id} failed (attempt {$envelope->attempts}/{$tries}), retrying: " . $exception->getMessage());
        $backoff = $job?->backoff() ?? 5;
        $queue->release($envelope, $backoff);

        $this->event(new Events\JobExceptionOccurred($envelope, null, $exception));
        $this->event(new Events\JobReleased($envelope, null, $backoff));
    }

    // ── Signals & supervision ─────────────────────────────────────────

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return; // Windows or pcntl-disabled build — fall back to
                    // process-supervisor SIGKILL behaviour.
        }
        pcntl_async_signals(true);
        $stop = function (): void { $this->shouldStop = true; };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        if (defined('SIGQUIT')) pcntl_signal(SIGQUIT, $stop);
    }

    /**
     * Sleep in 1-second chunks so an incoming signal can break us out
     * before the full duration elapses. Without this, a SIGTERM during
     * a long $sleep would wait its full duration before the worker
     * notices and exits.
     */
    private function stoppableSleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds; $i++) {
            if ($this->shouldStop) return;
            sleep(1);
        }
    }

    private function memoryExceeded(int $limitMb): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $limitMb;
    }

    /**
     * Reads the deploy-restart signal from cache. queue:restart bumps
     * the value; on each tick the worker compares the current value to
     * what it saw at boot and exits if they differ. Pairs with a
     * supervisor that auto-respawns the worker — old code drains
     * before new code starts.
     */
    private function readRestartSignal(): ?int
    {
        if (!$this->cache) return null;
        try {
            $value = $this->cache->get('queue:restart');
            return is_numeric($value) ? (int) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function restartSignalled(): bool
    {
        $current = $this->readRestartSignal();
        return $current !== null && $current !== $this->cachedRestart;
    }
}
