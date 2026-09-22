<?php

namespace Nitro\Queue;

use Nitro\Cache\CacheManager;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Events\Contracts\Dispatcher as EventDispatcher;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Queue\Batching\Batch;
use Nitro\Queue\Batching\BatchCallbacks;
use Nitro\Queue\Batching\BatchRepository;
use Nitro\Queue\Contracts\FailedJobStore;
use Nitro\Queue\Contracts\Queue;
use Nitro\Queue\Contracts\ShouldBeUnique;
use Nitro\Queue\Contracts\ShouldBeUniqueUntilProcessing;
use Nitro\Queue\Exceptions\MaxAttemptsExceededException;
use Nitro\Queue\Exceptions\TimeoutExceededException;
use Nitro\Support\Pipeline;
use Throwable;

/**
 * The long-running process that pulls jobs off a queue and runs them.
 *
 *   php nitro queue:work                    # forever loop, default options
 *   php nitro queue:work --once             # process one job, then exit
 *   php nitro queue:work --queue=high,mail  # highest priority first
 *   php nitro queue:work --tries=5          # override default max attempts
 *
 * Lifecycle of one tick:
 *   1. Looping — a listener may hold the worker back for this turn.
 *   2. queue.pop() — atomically reserve next runnable job (or null),
 *      trying each named queue in the order it was given.
 *   3. If null: fire WorkerIdle, sleep $sleep seconds, loop.
 *   4. Arm the timeout alarm for this job.
 *   5. Decode the payload; if that throws, fail to the failed store.
 *   6. If the attempt budget is spent: fail (poison-pill from a crash).
 *   7. Container-resolve handle() dependencies; call handle().
 *   8. Success → queue.delete(). Throw → release with backoff, or fail
 *      if this attempt blew the budget.
 *   9. Check stop conditions and either continue or exit with a reason.
 *
 * Graceful shutdown: pcntl_signal traps SIGTERM/SIGINT and sets the
 * stop flag. The current job finishes; the loop exits before pulling
 * another. Worker supervisors (systemd, supervisord, Procfile, etc.)
 * can restart the process cleanly.
 *
 * Restart-on-deploy: queue:restart bumps a cache key. The worker reads
 * the key after each job and exits when it changes. Pairs with a
 * supervisor that auto-restarts — old code drains, new code starts.
 *
 * A frozen job is a different problem from a failing one: it never
 * throws, so nothing above catches it. SIGALRM is armed to the job's
 * timeout before each run and the process is killed outright when it
 * fires — the supervisor starts a fresh worker, and the job is either
 * failed or left for another attempt depending on failOnTimeout.
 */
class Worker
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_MEMORY_LIMIT = 12;

    /** Set from a signal handler; checked between jobs. */
    public bool $shouldQuit = false;

    /** The envelope currently being run, for the alarm handler to fail. */
    public ?QueuedJob $currentJob = null;

    private ?int $cachedRestart = null;

    private int $jobsProcessed = 0;

    private int|float|null $lastJobProcessedAt = null;

    /** Called after each job by the console command, to report progress. */
    private $onJob = null;

    public function __construct(
        private QueueManager $queues,
        private FailedJobStore $failedStore,
        private ClassResolver $resolver,
        private ?CacheManager $cache = null,
        private ?BatchRepository $batches = null,
        private ?BatchCallbacks $batchCallbacks = null,
        private ?UniqueLock $uniqueLock = null,
        private ?EventDispatcher $events = null,
        private ?ExceptionHandler $exceptions = null,
    ) {}

    /**
     * Run the worker loop.
     *
     * The array form is what the console command parses into; a caller
     * holding a WorkerOptions may pass it straight through.
     *
     * @param  array{
     *   connection?: ?string,
     *   queue?: string,
     *   sleep?: int|float,
     *   tries?: ?int,
     *   maxJobs?: int,
     *   maxTime?: int,
     *   maxMemory?: int,
     *   timeout?: int,
     *   rest?: int|float,
     *   backoff?: int|array<int, int>,
     *   force?: bool,
     *   stopWhenEmpty?: bool,
     *   stopWhenEmptyFor?: int,
     *   once?: bool,
     *   onJob?: ?callable
     * }|WorkerOptions $options
     * @return int One of the EXIT_* codes.
     */
    public function run(array|WorkerOptions $options = []): int
    {
        if ($options instanceof WorkerOptions) {
            return $this->daemon(null, 'default', $options);
        }

        $this->onJob = $options['onJob'] ?? null;

        // --once is "one job, then stop", which is stopWhenEmpty plus a
        // job cap of one rather than a mode of its own.
        $once = (bool) ($options['once'] ?? false);

        return $this->daemon(
            $options['connection'] ?? null,
            $options['queue'] ?? 'default',
            new WorkerOptions(
                name: 'default',
                backoff: $options['backoff'] ?? 0,
                memory: max(0, (int) ($options['maxMemory'] ?? 128)),
                timeout: max(0, (int) ($options['timeout'] ?? 60)),
                sleep: max(0, $options['sleep'] ?? 1),
                maxTries: isset($options['tries']) ? (int) $options['tries'] : null,
                force: (bool) ($options['force'] ?? false),
                stopWhenEmpty: $once || (bool) ($options['stopWhenEmpty'] ?? false),
                maxJobs: $once ? 1 : max(0, (int) ($options['maxJobs'] ?? 0)),
                maxTime: max(0, (int) ($options['maxTime'] ?? 0)),
                rest: max(0, $options['rest'] ?? 0),
                stopWhenEmptyFor: max(0, (int) ($options['stopWhenEmptyFor'] ?? 0)),
            ),
        );
    }

    /**
     * Listen to the given queue in a loop.
     *
     * @param string $queue One name, or several in priority order,
     *        comma-separated — each is drained before the next is asked.
     */
    public function daemon(?string $connectionName, string $queue, WorkerOptions $options): int
    {
        if ($supportsAsyncSignals = $this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        $this->cachedRestart = $this->readRestartSignal();

        $startTime = $this->currentTime();

        $this->jobsProcessed = 0;

        $connection = $this->queues->connection($connectionName);
        $connectionName ??= $connection->getConnectionName();

        $this->event(new Events\WorkerStarting($connectionName, $queue, $options));

        while (true) {
            // A listener may hold the worker back for one turn of the loop,
            // which is how work is kept off a queue during a migration
            // without stopping the process and losing the reservation.
            if (! $this->daemonShouldRun($options, $connectionName, $queue)) {
                [$status, $reason] = $this->pauseWorker($options, $startTime);

                if ($status !== null) {
                    return $this->stopping($status, $options, $reason, $connectionName, $queue);
                }

                continue;
            }

            $envelope = $this->getNextJob($connection, $queue);

            if ($supportsAsyncSignals) {
                $this->registerTimeoutHandler($connectionName, $queue, $envelope, $options);
            }

            if ($envelope !== null) {
                $this->jobsProcessed++;

                $this->runJob($connection, $connectionName, $envelope, $options);

                $this->lastJobProcessedAt = $this->currentTime();

                if ($this->onJob !== null) {
                    ($this->onJob)($envelope);
                }

                if ($options->rest > 0) {
                    $this->sleep($options->rest);
                }
            } else {
                $this->event(new Events\WorkerIdle($connectionName, $queue, $options));

                $this->sleep($options->sleep);
            }

            if ($supportsAsyncSignals) {
                $this->resetTimeoutHandler();
            }

            [$status, $reason] = $this->stopIfNecessary($options, $startTime, $envelope);

            if ($status !== null) {
                return $this->stopping($status, $options, $reason, $connectionName, $queue);
            }
        }
    }

    /**
     * Process one job and return, rather than looping.
     *
     * @return int One of the EXIT_* codes.
     */
    public function runNextJob(?string $connectionName, string $queue, WorkerOptions $options): int
    {
        $connection = $this->queues->connection($connectionName);
        $connectionName ??= $connection->getConnectionName();

        $envelope = $this->getNextJob($connection, $queue);

        if ($envelope === null) {
            $this->sleep($options->sleep);

            return static::EXIT_SUCCESS;
        }

        $this->jobsProcessed++;

        $this->runJob($connection, $connectionName, $envelope, $options);

        return static::EXIT_SUCCESS;
    }

    /**
     * Ask the loop to finish the job in hand and then exit.
     *
     * The job keeps its reservation until it is done, so nothing is
     * abandoned half-run; the worker exits before reserving another.
     */
    public function stop(): void
    {
        $this->shouldQuit = true;
    }

    /**
     * Announce that the worker is exiting, and with what exit code.
     *
     * Separate from stop() because a worker can be told to stop long
     * before it does: this is the moment it actually leaves the loop.
     */
    private function stopping(
        int $status,
        ?WorkerOptions $options,
        ?WorkerStopReason $reason,
        ?string $connectionName,
        string $queue,
    ): int {
        $this->event(new Events\WorkerStopping(
            $connectionName,
            $queue,
            $status,
            $reason,
            $options,
            $this->jobsProcessed,
            $this->lastJobProcessedAt,
            $this->currentMemoryUsage(),
        ));

        return $status;
    }

    /** Stop the process immediately, without finishing anything. */
    public function kill(
        int $status = 0,
        ?WorkerOptions $options = null,
        ?WorkerStopReason $reason = null,
        ?string $connectionName = null,
        string $queue = 'default',
    ): never {
        $this->stopping($status, $options, $reason, $connectionName, $queue);

        if (extension_loaded('posix') && defined('SIGKILL')) {
            posix_kill(getmypid(), SIGKILL);
        }

        exit($status);
    }

    // ── The loop's decisions ──────────────────────────────────────────

    /**
     * Whether this turn of the loop should look for work at all.
     *
     * A listener returning false from Looping holds the worker back;
     * so does maintenance mode, unless --force was given.
     */
    private function daemonShouldRun(WorkerOptions $options, ?string $connectionName, string $queue): bool
    {
        if (! $options->force && $this->isDownForMaintenance()) {
            return false;
        }

        $looping = new Events\Looping($connectionName, $queue, $options);

        return $this->until($looping) !== false;
    }

    /**
     * Wait out a turn the worker was held back for.
     *
     * @return array{0: ?int, 1: ?WorkerStopReason}
     */
    private function pauseWorker(WorkerOptions $options, int|float $startTime): array
    {
        $this->sleep($options->sleep > 0 ? $options->sleep : 1);

        return $this->stopIfNecessary($options, $startTime);
    }

    /**
     * The reason to exit, if there is one.
     *
     * Ordered so the involuntary reasons win: a worker that has lost its
     * connection or been signalled stops for that, not for whichever
     * limit it happened to cross on the same turn.
     *
     * @return array{0: ?int, 1: ?WorkerStopReason}
     */
    private function stopIfNecessary(
        WorkerOptions $options,
        int|float $startTime,
        ?QueuedJob $job = null,
    ): array {
        return match (true) {
            $this->shouldQuit => [static::EXIT_SUCCESS, WorkerStopReason::Interrupted],
            $this->memoryExceeded($options->memory) => [static::EXIT_MEMORY_LIMIT, WorkerStopReason::MaxMemoryExceeded],
            $this->restartSignalled() => [static::EXIT_SUCCESS, WorkerStopReason::ReceivedRestartSignal],
            $options->stopWhenEmpty && $job === null => [static::EXIT_SUCCESS, WorkerStopReason::QueueEmpty],
            $options->stopWhenEmptyFor > 0 && $job === null
                && $this->currentTime() - ($this->lastJobProcessedAt ?? $startTime) >= $options->stopWhenEmptyFor
                    => [static::EXIT_SUCCESS, WorkerStopReason::QueueEmptyFor],
            $options->maxTime > 0 && $this->currentTime() - $startTime >= $options->maxTime
                    => [static::EXIT_SUCCESS, WorkerStopReason::MaxTimeExceeded],
            $options->maxJobs > 0 && $this->jobsProcessed >= $options->maxJobs
                    => [static::EXIT_SUCCESS, WorkerStopReason::MaxJobsExceeded],
            default => [null, null],
        };
    }

    /**
     * Reserve the next job, trying each named queue in turn.
     *
     * Several queues given as one comma-separated string are a priority
     * order, not a set: the second is only asked once the first has
     * nothing left, which is what makes a 'high,default' worker drain
     * urgent work first.
     *
     * A driver that throws here — a database that went away, a redis
     * that refused the connection — must not take the worker down with
     * it. The failure is reported and the loop pauses a second, on the
     * reading that the backend will come back and the supervisor should
     * not be restarting the process every few milliseconds meanwhile.
     */
    private function getNextJob(Queue $connection, string $queue): ?QueuedJob
    {
        $connectionName = $connection->getConnectionName();

        $this->event(new Events\JobPopping($connectionName, $queue));

        try {
            foreach (explode(',', $queue) as $name) {
                $name = trim($name);

                if ($name === '') {
                    continue;
                }

                if (($job = $connection->pop($name)) !== null) {
                    $this->event(new Events\JobPopped($job, $connectionName));

                    return $job;
                }
            }
        } catch (Throwable $exception) {
            $this->report($exception);

            $this->sleep(1);
        }

        return null;
    }

    // ── Running one job ───────────────────────────────────────────────

    /**
     * Run a job, keeping whatever it throws from reaching the loop.
     *
     * handleJobException re-throws after releasing or failing, so the
     * job's own outcome is already settled by the time this catches;
     * what is left is to report it and carry on.
     */
    private function runJob(
        Queue $queue,
        ?string $connectionName,
        QueuedJob $envelope,
        WorkerOptions $options,
    ): void {
        $this->currentJob = $envelope;

        try {
            $this->process($queue, $connectionName, $envelope, $options);
        } catch (Throwable $exception) {
            $this->report($exception);
        } finally {
            $this->currentJob = null;
        }
    }

    /**
     * Decode a job, run it, and settle what happens next.
     *
     * @throws Throwable Whatever the job threw, after it has been
     *         released or failed.
     */
    public function process(
        Queue $queue,
        ?string $connectionName,
        QueuedJob $envelope,
        WorkerOptions $options,
    ): void {
        $job = null;
        $exceptionOccurred = null;

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

            $this->event(new Events\JobProcessing($envelope, $connectionName));

            // Poison-pill guard: a job that comes off the queue with its
            // budget already spent means a prior worker crashed mid-handle
            // and the reservation expired. handle() is not run again.
            $this->failIfAlreadyExceededAttempts($envelope, $job, $options);

            if ($this->isDeleted($job)) {
                $this->event(new Events\JobProcessed($envelope, $connectionName));

                return;
            }

            if ($job instanceof ShouldBeUniqueUntilProcessing) {
                $this->releaseUniqueLock($job);
            }

            $this->invoke($job);

            // A job that released or failed itself inside handle() has
            // already said where it should go; deleting it here as well
            // would undo that and drop the retry on the floor.
            if (! $this->isReleased($job) && ! $this->hasFailed($job)) {
                $queue->delete($envelope);
            }

            if ($job instanceof ShouldBeUnique) {
                $this->releaseUniqueLock($job);
            }

            $this->event(new Events\JobProcessed($envelope, $connectionName));

            if (! $this->isReleased($job) && ! $this->hasFailed($job)) {
                $this->recordBatchSuccess($job, $envelope);
            }

            if ($this->isReleased($job)) {
                $this->event(new Events\JobReleased($envelope, $connectionName));
            }
        } catch (Throwable $exception) {
            $exceptionOccurred = $exception;

            $this->handleJobException($queue, $connectionName, $envelope, $job, $options, $exception);
        } finally {
            $this->event(new Events\JobAttempted($envelope, $connectionName, $exceptionOccurred));
        }
    }

    /**
     * Decide what a thrown job deserves, then re-throw.
     *
     * Failing is checked before releasing so a job that has run out of
     * attempts is not put back for one more that would only fail the
     * same way.
     *
     * @throws Throwable Always — the caller reports it.
     */
    private function handleJobException(
        Queue $queue,
        ?string $connectionName,
        QueuedJob $envelope,
        ?Job $job,
        WorkerOptions $options,
        Throwable $exception,
    ): void {
        // Whether the worker itself failed the job, which is not the same
        // question as whether the job failed itself: a plain job has no
        // hasFailed() to ask, and releasing one the worker just recorded
        // as failed would queue a retry that can never run.
        $failed = $this->hasFailed($job);

        try {
            if (! $failed) {
                // A payload that would not decode can never succeed on a
                // retry — there is no class to run — so it fails outright
                // rather than being released to loop until its budget runs
                // out one useless attempt at a time.
                if ($job === null || $exception instanceof MaxAttemptsExceededException) {
                    $this->failJob($queue, $connectionName, $envelope, $job, $exception);
                    $failed = true;
                } else {
                    $failed = $this->failIfWillExceedAttempts($queue, $connectionName, $envelope, $job, $options, $exception)
                        || $this->failIfWillExceedExceptions($queue, $connectionName, $envelope, $job, $exception)
                        || $this->failIfItShouldNotBeRetried($queue, $connectionName, $envelope, $job, $exception);
                }
            }

            $this->event(new Events\JobExceptionOccurred($envelope, $connectionName, $exception));
        } finally {
            if (! $failed && ! $this->isDeleted($job) && ! $this->isReleased($job)) {
                $backoff = $this->calculateBackoff($job, $envelope, $options);

                $queue->release($envelope, $backoff);

                $this->event(new Events\JobReleased($envelope, $connectionName, $backoff));
                $this->event(new Events\JobReleasedAfterException(
                    $envelope, $connectionName, $backoff, $exception
                ));
            }
        }

        throw $exception;
    }

    /**
     * Fail a job whose budget was already spent when it was reserved.
     *
     * @throws MaxAttemptsExceededException When it was.
     */
    private function failIfAlreadyExceededAttempts(
        QueuedJob $envelope,
        Job $job,
        WorkerOptions $options,
    ): void {
        $maxTries = $this->maxTriesFor($job, $options);
        $retryUntil = $job->retryUntil();

        // A time budget replaces the count entirely: a job worth retrying
        // for an hour should not stop after three quick failures.
        if ($retryUntil !== null && time() <= $retryUntil) {
            return;
        }

        if ($retryUntil === null && ($maxTries === 0 || $envelope->attempts <= $maxTries)) {
            return;
        }

        throw MaxAttemptsExceededException::forJob($envelope, $job::class);
    }

    /**
     * Fail a job now rather than release it for an attempt it cannot have.
     *
     * @return bool Whether the job was failed.
     */
    private function failIfWillExceedAttempts(
        Queue $queue,
        ?string $connectionName,
        QueuedJob $envelope,
        Job $job,
        WorkerOptions $options,
        Throwable $exception,
    ): bool {
        $maxTries = $this->maxTriesFor($job, $options);
        $retryUntil = $job->retryUntil();

        if ($retryUntil !== null) {
            if ($retryUntil > time()) {
                return false;
            }

            $this->failJob($queue, $connectionName, $envelope, $job, $exception);

            return true;
        }

        if ($maxTries > 0 && $envelope->attempts >= $maxTries) {
            $this->failJob($queue, $connectionName, $envelope, $job, $exception);

            return true;
        }

        return false;
    }

    /**
     * Fail a job that has thrown more often than it is allowed to.
     *
     * Counted separately from attempts because a job that releases
     * itself can attempt many times without ever throwing; the count
     * is held in the cache against the job's own identifier, which
     * survives the row being rewritten on each release.
     *
     * @return bool Whether the job was failed.
     */
    private function failIfWillExceedExceptions(
        Queue $queue,
        ?string $connectionName,
        QueuedJob $envelope,
        Job $job,
        Throwable $exception,
    ): bool {
        $maxExceptions = $job->maxExceptions();
        $uuid = $envelope->uuid();

        if ($this->cache === null || $maxExceptions === null || $uuid === null) {
            return false;
        }

        $key = 'job-exceptions:' . $uuid;

        if (! $this->cache->get($key)) {
            $this->cache->put($key, 0, 86400);
        }

        if ($maxExceptions > (int) $this->cache->increment($key)) {
            return false;
        }

        $this->cache->forget($key);

        $this->failJob($queue, $connectionName, $envelope, $job, $exception);

        return true;
    }

    /**
     * Fail a job whose exception the handler has ruled out retrying.
     *
     * Some failures are not worth a second attempt whatever the budget
     * says — a validation error, a record that no longer exists — and
     * the application decides which through the handler's dontRetry
     * rules rather than each job repeating the same check.
     *
     * @return bool Whether the job was failed.
     */
    private function failIfItShouldNotBeRetried(
        Queue $queue,
        ?string $connectionName,
        QueuedJob $envelope,
        Job $job,
        Throwable $exception,
    ): bool {
        if ($this->exceptions === null || ! $this->exceptions->shouldStopRetries($exception)) {
            return false;
        }

        $this->failJob($queue, $connectionName, $envelope, $job, $exception);

        return true;
    }

    /**
     * Record a job as permanently failed and take it off the queue.
     *
     * The failed() hook is called inside its own try: a notification
     * that throws must not stop the job being recorded, and by this
     * point there is nothing further the worker can do about it anyway.
     */
    private function failJob(
        Queue $queue,
        ?string $connectionName,
        QueuedJob $envelope,
        ?Job $job,
        Throwable $exception,
    ): void {
        $this->failedStore->log($envelope, $exception);

        $queue->delete($envelope);

        if ($job === null) {
            $this->event(new Events\JobFailed($envelope, $connectionName, $exception));

            return;
        }

        if ($job instanceof ShouldBeUnique) {
            $this->releaseUniqueLock($job);
        }

        $this->event(new Events\JobFailed($envelope, $connectionName, $exception));

        try {
            $job->failed($exception);
        } catch (Throwable $hookError) {
            error_log('[queue] failed() hook threw: ' . $hookError->getMessage());
        }

        $this->recordBatchFailure($job, $envelope, $exception);
    }

    /**
     * Seconds to wait before the next attempt.
     *
     * A job may give one value per attempt, so a schedule that starts
     * fast and backs off — [1, 10, 60] — needs no arithmetic at the
     * call site. Past the end of the list the last value repeats.
     */
    private function calculateBackoff(?Job $job, QueuedJob $envelope, WorkerOptions $options): int
    {
        $backoff = $job?->backoff() ?? $options->backoff;

        if (is_string($backoff)) {
            $backoff = explode(',', $backoff);
        }

        if (! is_array($backoff)) {
            return max(0, (int) $backoff);
        }

        $backoff = array_values($backoff);

        if ($backoff === []) {
            return 0;
        }

        $attempt = max(1, $envelope->attempts);

        return max(0, (int) ($backoff[$attempt - 1] ?? end($backoff)));
    }

    /**
     * The attempt cap for this job.
     *
     * A --tries on the command line was asked for explicitly and wins,
     * including --tries=0 for no cap at all; without one each job's own
     * $tries decides, which is where it belongs.
     */
    private function maxTriesFor(Job $job, WorkerOptions $options): int
    {
        return $options->maxTries ?? $job->tries();
    }

    // ── Running the job itself ────────────────────────────────────────

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

    // ── What a job said about itself ──────────────────────────────────

    /**
     * These three ask a job what it did to its own place in the queue,
     * which only a job using InteractsWithQueue can answer. A plain job
     * has taken no such action, so the answer is no.
     */
    private function isDeleted(?Job $job): bool
    {
        return $job !== null && method_exists($job, 'isDeleted') && $job->isDeleted();
    }

    private function isReleased(?Job $job): bool
    {
        return $job !== null && method_exists($job, 'isReleased') && $job->isReleased();
    }

    private function hasFailed(?Job $job): bool
    {
        return $job !== null && method_exists($job, 'hasFailed') && $job->hasFailed();
    }

    // ── Batches and unique locks ──────────────────────────────────────

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

    /** The batch a job belongs to, or null when it is not batched. */
    private function batchOf(Job $job): ?Batch
    {
        if (! property_exists($job, 'batchId') || $job->batchId === null) {
            return null;
        }

        return $this->batches?->find($job->batchId);
    }

    // ── Signals & supervision ─────────────────────────────────────────

    private function supportsAsyncSignals(): bool
    {
        return extension_loaded('pcntl')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals');
    }

    private function listenForSignals(): void
    {
        pcntl_async_signals(true);

        // Named rather than referenced: a build without pcntl has no
        // SIGTERM constant either, and naming an undefined constant is
        // a fatal error rather than something to guard around.
        foreach (['SIGTERM', 'SIGINT', 'SIGQUIT'] as $signal) {
            if (! defined($signal)) {
                continue;
            }

            pcntl_signal(constant($signal), function (): void {
                $this->shouldQuit = true;
            });
        }
    }

    /**
     * Arm the alarm that kills a job which has stopped making progress.
     *
     * A frozen job never throws, so nothing in the normal path catches
     * it: the process has to be killed from outside the call. Whether
     * the job is failed first is the job's own choice — a timeout is
     * usually a slow dependency rather than a broken job, so the
     * default leaves it for another attempt.
     */
    private function registerTimeoutHandler(
        ?string $connectionName,
        string $queue,
        ?QueuedJob $envelope,
        WorkerOptions $options,
    ): void {
        if (! defined('SIGALRM')) {
            return;
        }

        $job = $envelope !== null ? $this->decodeQuietly($envelope) : null;

        pcntl_signal(SIGALRM, function () use ($connectionName, $queue, $envelope, $job, $options): void {
            if ($envelope !== null) {
                $timeout = $this->timeoutForJob($job, $options);
                $exception = TimeoutExceededException::forJob($envelope, $envelope->resolveName());

                if ($job !== null && $job->shouldFailOnTimeout()) {
                    $this->failedStore->log($envelope, $exception);
                    $this->event(new Events\JobFailed($envelope, $connectionName, $exception));
                }

                $this->event(new Events\JobTimedOut($envelope, $connectionName, $timeout));
            }

            $this->kill(static::EXIT_ERROR, $options, WorkerStopReason::TimedOut, $connectionName, $queue);
        }, true);

        pcntl_alarm(max($this->timeoutForJob($job, $options), 0));
    }

    private function resetTimeoutHandler(): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }

    /** The job's own limit, or the worker's when it names none. */
    private function timeoutForJob(?Job $job, WorkerOptions $options): int
    {
        return $job?->timeout() ?? $options->timeout;
    }

    /**
     * Decode a job without letting a bad payload reach the alarm.
     *
     * The handler only needs the job's knobs, and a payload that will
     * not decode is about to fail on its own in process() — throwing
     * here instead would replace that with a less useful error.
     */
    private function decodeQuietly(QueuedJob $envelope): ?Job
    {
        try {
            return $envelope->decode()['instance'];
        } catch (Throwable) {
            return null;
        }
    }

    private function memoryExceeded(int $limitMb): bool
    {
        return $limitMb > 0 && $this->currentMemoryUsage() >= $limitMb;
    }

    private function currentMemoryUsage(): int|float
    {
        return memory_get_usage(true) / 1024 / 1024;
    }

    private function currentTime(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * Sleep, in whole seconds or a fraction of one.
     *
     * Broken into one-second chunks so an incoming signal can end the
     * wait early — without it a SIGTERM during a long --sleep waits out
     * its full duration before the worker notices.
     */
    public function sleep(int|float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if ($seconds < 1) {
            usleep((int) ($seconds * 1_000_000));

            return;
        }

        for ($slept = 0; $slept < $seconds; $slept++) {
            if ($this->shouldQuit) {
                return;
            }

            sleep(1);
        }
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
        if ($this->cache === null) {
            return null;
        }

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

    /** Whether the application is down, when anything can say. */
    private function isDownForMaintenance(): bool
    {
        return false;
    }

    // ── Events ────────────────────────────────────────────────────────

    /** Dispatch a lifecycle event, when anything is listening. */
    private function event(object $event): void
    {
        $this->events?->dispatch($event);
    }

    /**
     * Dispatch an event a listener may answer.
     *
     * Returns the first non-null answer, so a Looping listener can hold
     * the worker back by returning false.
     */
    private function until(object $event): mixed
    {
        if ($this->events === null) {
            return null;
        }

        return method_exists($this->events, 'until')
            ? $this->events->until($event)
            : $this->events->dispatch($event);
    }

    /**
     * Record something that went wrong outside a job's own handling.
     *
     * A connection failure or a re-thrown job exception is not itself a
     * failed job, and losing it silently is how a worker ends up
     * looking idle while nothing is being processed. Without a handler
     * to take it there is still stderr, which a supervisor captures.
     */
    private function report(Throwable $exception): void
    {
        if ($this->exceptions !== null) {
            $this->exceptions->report($exception);

            return;
        }

        error_log('[queue] ' . $exception::class . ': ' . $exception->getMessage());
    }
}
