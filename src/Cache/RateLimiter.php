<?php

namespace Nitro\Cache;

use Closure;

/**
 * Cache-backed rate limiter — the primitive behind login lockout, the throttle
 * middleware, and any "N attempts per window" guard. Mirrors the surface of
 * Laravel's Illuminate\Cache\RateLimiter.
 *
 * Counters live in the cache keyed by a hash of the caller's key (email, IP,
 * route, …) with a matching decay TTL, plus a sibling `:timer` entry that marks
 * when the current window resets (so availableIn() counts down from the first
 * hit rather than the last).
 */
class RateLimiter
{
    public function __construct(private Repository $cache) {}

    /**
     * Execute $callback if the key is under its cap; return false (without
     * running it) once the cap is reached. Each execution costs one hit for
     * $decaySeconds.
     */
    public function attempt(string $key, int $maxAttempts, Closure $callback, int $decaySeconds = 60): mixed
    {
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        $this->hit($key, $decaySeconds);

        return $callback() ?? true;
    }

    /** Whether the key has reached its attempt cap within the current window. */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        if ($this->attempts($key) >= $maxAttempts) {
            if ($this->cache->has($this->key($key) . ':timer')) {
                return true;
            }
            // Window elapsed — clear the stale counter and start fresh.
            $this->resetAttempts($key);
        }

        return false;
    }

    /** Record one attempt, opening the decay window on the first hit. Returns the new count. */
    public function hit(string $key, int $decaySeconds = 60): int
    {
        $k = $this->key($key);

        // Open the window timer once, so it counts down from the first hit.
        if (! $this->cache->has($k . ':timer')) {
            $this->cache->put($k . ':timer', time() + $decaySeconds, $decaySeconds);
        }

        // get+put (not increment) so the counter always carries the decay TTL —
        // some stores' increment resets missing keys to a far-future expiry.
        $hits = ((int) $this->cache->get($k, 0)) + 1;
        $this->cache->put($k, $hits, $decaySeconds);

        return $hits;
    }

    /** How many attempts have been recorded for the key in the current window. */
    public function attempts(string $key): int
    {
        return (int) $this->cache->get($this->key($key), 0);
    }

    /** Reset the attempt counter for the key (leaves the timer). */
    public function resetAttempts(string $key): bool
    {
        return $this->cache->forget($this->key($key));
    }

    /** Attempts remaining before the cap is hit. */
    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    /** Seconds until the key's window resets (0 when not limited). */
    public function availableIn(string $key): int
    {
        return max(0, (int) $this->cache->get($this->key($key) . ':timer', 0) - time());
    }

    /** Clear both the counter and the window timer for a key. */
    public function clear(string $key): void
    {
        $this->resetAttempts($key);
        $this->cache->forget($this->key($key) . ':timer');
    }

    /** Namespace + hash so arbitrary inputs (email, ip) are safe cache keys. */
    protected function key(string $key): string
    {
        return 'ratelimit:' . sha1($key);
    }
}
