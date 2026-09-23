<?php

namespace Nitro\Queue\Middleware;

use Closure;
use Nitro\Cache\RateLimiter;
use Throwable;

/**
 * Stops hammering a failing dependency.
 *
 *     public function middleware(): array
 *     {
 *         return [(new ThrottlesExceptions(5, 300))->backoff(5)];
 *     }
 *
 * Once a job's exceptions reach the limit inside the window, the circuit
 * opens: further jobs are released without being attempted until it closes,
 * so a broken API is not called once per queued job.
 *
 * A throttled job is released rather than failed, and the exception is
 * not re-thrown, so an outage does not spend the job's attempts.
 */
class ThrottlesExceptions
{
    /** Names the circuit; jobs sharing a key share one. */
    protected string $key = '';

    /** Whether the circuit is per queued job rather than per class. */
    protected bool $byJob = false;

    /** @var int|Closure(Throwable): int Minutes to wait after an exception. */
    protected int|Closure $retryAfterMinutes = 0;

    /** Decides whether an exception is reported. */
    protected mixed $reportCallback = null;

    /** Decides whether the throttle applies to an exception at all. */
    protected mixed $whenCallback = null;

    /** @var array<int, callable> Decide whether the job is dropped. */
    protected array $deleteWhenCallbacks = [];

    /** @var array<int, callable> Decide whether the job is failed outright. */
    protected array $failWhenCallbacks = [];

    protected string $prefix = 'nitro_throttles_exceptions:';

    protected bool $deleteWhenThrottled = false;

    protected ?RateLimiter $limiter = null;

    /**
     * @param int $maxAttempts  Failures tolerated before the circuit opens.
     * @param int $decaySeconds How long the circuit stays open.
     */
    public function __construct(
        protected int $maxAttempts = 10,
        protected int $decaySeconds = 600,
    ) {}

    public function handle(mixed $job, callable $next): mixed
    {
        $this->limiter = $this->limiter();

        if ($this->limiter === null) {
            return $next($job);
        }

        $jobKey = $this->getKey($job);

        if ($this->limiter->tooManyAttempts($jobKey, $this->maxAttempts)) {
            return $this->throttled($job, null);
        }

        try {
            $result = $next($job);

            $this->limiter->clear($jobKey);

            return $result;
        } catch (Throwable $throwable) {
            // An uncovered exception travels on to the worker as usual.
            if ($this->whenCallback !== null && ! ($this->whenCallback)($throwable, $this->limiter)) {
                throw $throwable;
            }

            if ($this->reportCallback !== null && ($this->reportCallback)($throwable, $this->limiter)) {
                $this->reportThrowable($throwable);
            }

            if ($this->shouldDelete($throwable)) {
                return $this->delete($job);
            }

            if ($this->shouldFail($throwable)) {
                return $this->fail($job, $throwable);
            }

            $this->limiter->hit($jobKey, $this->decaySeconds);

            return $this->release($job, $this->timeUntilNextRetryAfterException($throwable));
        }
    }

    /** Namespace the counter so unrelated jobs do not share a circuit. */
    public function by(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Give each queued job its own circuit.
     *
     * The default groups by class, for a shared dependency.
     */
    public function byJob(): static
    {
        $this->byJob = true;

        return $this;
    }

    /** Minutes to wait before a throttled job is tried again. */
    public function backoff(int|Closure $backoff): static
    {
        $this->retryAfterMinutes = $backoff;

        return $this;
    }

    /**
     * Decide whether the throttle applies to an exception.
     *
     * A class name counts only those; a callback covers the rest.
     *
     * @param array<int, class-string<Throwable>>|class-string<Throwable>|callable $exceptions
     */
    public function when(array|string|callable $exceptions): static
    {
        if (is_callable($exceptions) && ! is_string($exceptions)) {
            $this->whenCallback = $exceptions;

            return $this;
        }

        $types = (array) $exceptions;

        $this->whenCallback = static function (Throwable $throwable) use ($types): bool {
            foreach ($types as $type) {
                if ($throwable instanceof $type) {
                    return true;
                }
            }

            return false;
        };

        return $this;
    }

    /** Drop the job when the exception matches. */
    public function deleteWhen(callable|string $callback): static
    {
        $this->deleteWhenCallbacks[] = is_string($callback)
            ? static fn (Throwable $e): bool => $e instanceof $callback
            : $callback;

        return $this;
    }

    /** Fail the job outright when the exception matches. */
    public function failWhen(callable|string $callback): static
    {
        $this->failWhenCallbacks[] = is_string($callback)
            ? static fn (Throwable $e): bool => $e instanceof $callback
            : $callback;

        return $this;
    }

    /** Report throttled exceptions, optionally only some of them. */
    public function report(?callable $callback = null): static
    {
        $this->reportCallback = $callback ?? static fn (): bool => true;

        return $this;
    }

    /** Name the circuit's keys, to keep two throttles apart in one cache. */
    public function withPrefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /** Drop a throttled job rather than queueing it again. */
    public function deleteWhenThrottled(bool $delete = true): static
    {
        $this->deleteWhenThrottled = $delete;

        return $this;
    }

    // ── Internals ─────────────────────────────────────────────────────

    /** What happens to a job that arrives while the circuit is open. */
    protected function throttled(mixed $job, ?Throwable $exception): mixed
    {
        if ($this->deleteWhenThrottled) {
            return $this->delete($job);
        }

        return $this->release($job, $this->timeUntilNextRetry($this->getKey($job)));
    }

    protected function shouldDelete(Throwable $throwable): bool
    {
        foreach ($this->deleteWhenCallbacks as $callback) {
            if ($callback($throwable)) {
                return true;
            }
        }

        return false;
    }

    protected function shouldFail(Throwable $throwable): bool
    {
        foreach ($this->failWhenCallbacks as $callback) {
            if ($callback($throwable)) {
                return true;
            }
        }

        return false;
    }

    protected function timeUntilNextRetryAfterException(Throwable $throwable): int
    {
        $backoff = $this->retryAfterMinutes instanceof Closure
            ? ($this->retryAfterMinutes)($throwable)
            : $this->retryAfterMinutes;

        return (int) $backoff * 60;
    }

    /**
     * Seconds until the circuit closes, plus a margin.
     *
     * The margin keeps a job released at the boundary from arriving a
     * tick early and being turned away again.
     */
    protected function timeUntilNextRetry(string $key): int
    {
        return ($this->limiter?->availableIn($key) ?? $this->decaySeconds) + 3;
    }

    protected function getKey(mixed $job): string
    {
        if ($this->key !== '') {
            return $this->prefix . $this->key;
        }

        if ($this->byJob && is_object($job) && method_exists($job, 'uuid')) {
            return $this->prefix . $job->uuid();
        }

        return $this->prefix . hash('xxh128', is_object($job) ? $job::class : 'job');
    }

    /**
     * These three ask the job to act on its own place in the queue,
     * which only a job using InteractsWithQueue can do.
     */
    protected function release(mixed $job, int $delay): mixed
    {
        if (is_object($job) && method_exists($job, 'release')) {
            $job->release($delay);
        }

        return null;
    }

    protected function delete(mixed $job): mixed
    {
        if (is_object($job) && method_exists($job, 'delete')) {
            $job->delete();
        }

        return null;
    }

    protected function fail(mixed $job, Throwable $throwable): mixed
    {
        if (is_object($job) && method_exists($job, 'fail')) {
            $job->fail($throwable);
        }

        return null;
    }

    /** Hand a throttled exception to the application's handler. */
    private function reportThrowable(Throwable $throwable): void
    {
        try {
            app(\Nitro\Exceptions\ExceptionHandler::class)->report($throwable);
        } catch (Throwable) {
            error_log('[queue] throttled: ' . $throwable::class . ': ' . $throwable->getMessage());
        }
    }

    private function limiter(): ?RateLimiter
    {
        try {
            return app(RateLimiter::class);
        } catch (Throwable) {
            return null;
        }
    }
}
