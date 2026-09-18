<?php

namespace Nitro\Queue\Middleware;

use Nitro\Cache\Repository;
use Throwable;

/**
 * Stops hammering a failing dependency.
 *
 *     public function middleware(): array
 *     {
 *         return [(new ThrottlesExceptions(5, 300))->backoff(60)];
 *     }
 *
 * Once a job's exceptions reach the limit inside the window, the circuit
 * opens: further jobs are released without being attempted until it closes,
 * so a broken API is not called once per queued job.
 */
class ThrottlesExceptions
{
    protected string $key = '';

    /** Seconds to wait when releasing a job while the circuit is open. */
    protected int $retryAfter = 0;

    /** @var array<int, class-string<Throwable>> Types that count; all when empty. */
    protected array $only = [];

    /** Decides whether an exception counts. */
    protected mixed $whenCallback = null;

    /** Runs when a job is throttled rather than attempted. */
    protected mixed $reportCallback = null;

    protected bool $deleteWhenThrottled = false;

    /**
     * @param int $maxAttempts Failures tolerated before the circuit opens.
     * @param int $decayMinutes How long the circuit stays open, in minutes.
     */
    public function __construct(
        protected int $maxAttempts = 10,
        protected int $decayMinutes = 10,
    ) {}

    /** Namespace the counter so unrelated jobs do not share a circuit. */
    public function by(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /** Seconds to wait before a throttled job is tried again. */
    public function backoff(int $seconds): static
    {
        $this->retryAfter = $seconds;

        return $this;
    }

    /**
     * Count only these exception types.
     *
     * @param array<int, class-string<Throwable>>|class-string<Throwable> $exceptions
     */
    public function when(array|string|callable $exceptions): static
    {
        if (is_callable($exceptions) && ! is_string($exceptions)) {
            $this->whenCallback = $exceptions;

            return $this;
        }

        $this->only = (array) $exceptions;

        return $this;
    }

    /** Called with the exception each time a job is throttled. */
    public function report(callable $callback): static
    {
        $this->reportCallback = $callback;

        return $this;
    }

    /** Drop a throttled job rather than queueing it again. */
    public function deleteWhenThrottled(bool $delete = true): static
    {
        $this->deleteWhenThrottled = $delete;

        return $this;
    }

    public function handle(mixed $job, callable $next): mixed
    {
        $cache = $this->cache();

        if ($cache === null) {
            return $next($job);
        }

        $circuitKey = $this->circuitKey($job);

        if ($cache->has($circuitKey)) {
            return $this->throttled($job, null);
        }

        try {
            return $next($job);
        } catch (Throwable $exception) {
            if (! $this->counts($exception)) {
                throw $exception;
            }

            $failures = (int) $cache->get($this->counterKey($job), 0) + 1;

            $cache->put($this->counterKey($job), $failures, $this->decayMinutes * 60);

            if ($failures >= $this->maxAttempts) {
                $cache->put($circuitKey, 1, $this->decayMinutes * 60);
            }

            throw $exception;
        }
    }

    private function throttled(mixed $job, ?Throwable $exception): mixed
    {
        if ($this->reportCallback !== null) {
            ($this->reportCallback)($exception);
        }

        if ($this->deleteWhenThrottled) {
            if (method_exists($job, 'delete')) {
                $job->delete();
            }

            return null;
        }

        if (method_exists($job, 'release')) {
            $job->release($this->retryAfter > 0 ? $this->retryAfter : $this->decayMinutes * 60);
        }

        return null;
    }

    private function counts(Throwable $exception): bool
    {
        if ($this->whenCallback !== null) {
            return (bool) ($this->whenCallback)($exception);
        }

        if ($this->only === []) {
            return true;
        }

        foreach ($this->only as $type) {
            if ($exception instanceof $type) {
                return true;
            }
        }

        return false;
    }

    private function circuitKey(mixed $job): string
    {
        return 'throttle-open:' . $this->namespace($job);
    }

    private function counterKey(mixed $job): string
    {
        return 'throttle-count:' . $this->namespace($job);
    }

    private function namespace(mixed $job): string
    {
        return $this->key !== '' ? $this->key : (is_object($job) ? $job::class : 'job');
    }

    private function cache(): ?Repository
    {
        try {
            return app(Repository::class);
        } catch (Throwable) {
            return null;
        }
    }
}
