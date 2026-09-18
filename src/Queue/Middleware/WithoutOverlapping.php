<?php

namespace Nitro\Queue\Middleware;

use Nitro\Cache\Repository;
use Throwable;

/**
 * Stops two jobs sharing a key from running at the same time.
 *
 *     public function middleware(): array
 *     {
 *         return [(new WithoutOverlapping($this->userId))->releaseAfter(30)];
 *     }
 *
 * A second job holding the same key is released back to the queue, or
 * dropped when dontRelease() was asked for.
 */
class WithoutOverlapping
{
    /** Seconds before an unreleased lock lapses on its own. */
    protected int $expiresAfter = 3600;

    /** Seconds to wait before retrying, or null to drop the job. */
    protected ?int $releaseAfter = 0;

    protected string $prefix = 'overlap';

    public function __construct(
        public mixed $key = '',
    ) {}

    /** Seconds to wait before the blocked job is tried again. */
    public function releaseAfter(int $seconds): static
    {
        $this->releaseAfter = $seconds;

        return $this;
    }

    /** Drop the blocked job rather than queueing it again. */
    public function dontRelease(): static
    {
        $this->releaseAfter = null;

        return $this;
    }

    /** How long the lock survives if the worker dies holding it. */
    public function expireAfter(int $seconds): static
    {
        $this->expiresAfter = $seconds;

        return $this;
    }

    /** Namespace the lock so unrelated jobs sharing a key do not collide. */
    public function withPrefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /** The cache key this middleware locks on. */
    public function getLockKey(mixed $job): string
    {
        return $this->prefix . ':' . (is_object($job) ? $job::class : 'job') . ':' . $this->stringKey();
    }

    public function handle(mixed $job, callable $next): mixed
    {
        $cache = $this->cache();

        if ($cache === null) {
            return $next($job);
        }

        $lockKey = $this->getLockKey($job);

        if (! $cache->add($lockKey, 1, $this->expiresAfter)) {
            if ($this->releaseAfter !== null && method_exists($job, 'release')) {
                $job->release($this->releaseAfter);
            }

            return null;
        }

        try {
            return $next($job);
        } finally {
            $cache->forget($lockKey);
        }
    }

    private function stringKey(): string
    {
        return is_scalar($this->key) ? (string) $this->key : md5(serialize($this->key));
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
