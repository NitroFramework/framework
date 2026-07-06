<?php

namespace Nitro\Htmx\State;

use Nitro\Cache\CacheManager;
use Nitro\Cache\Repository;

/**
 * Wraps Nitro's existing cache layer so component state can live in any
 * configured cache driver — redis, file, memcached, array. Pick the
 * driver via config('htmx.state.cache_driver'); falls back to whatever
 * cache the app considers its default.
 *
 * Unlike SessionStateStore, the cache backend is GLOBAL — keys are not
 * automatically scoped per user. This store prefixes every key with the
 * current PHP session ID so user A's state can't collide with user B's.
 * Anonymous users (no session) share a single "guest" bucket — usually
 * fine for non-auth use cases like demo pages.
 */
class CacheStateStore implements StateStore
{
    private Repository $cache;

    public function __construct(
        CacheManager $manager,
        ?string $driver = null,
        private ?int $defaultTtl = null,
    ) {
        $this->cache = $manager->store($driver);
    }

    public function get(string $key): ?array
    {
        $value = $this->cache->get($this->scopedKey($key));
        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $value, ?int $ttl = null): void
    {
        $this->cache->put($this->scopedKey($key), $value, $ttl ?? $this->defaultTtl);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->scopedKey($key));
    }

    /**
     * Prefix the key with a per-user scope. PHP's session ID is the
     * natural choice — guaranteed unique per browser session, already
     * available via session_id(), and survives across requests as long
     * as the session cookie does. For requests without a session, fall
     * back to a shared "guest" bucket.
     */
    private function scopedKey(string $key): string
    {
        $scope = (function_exists('session_id') && session_id() !== '')
            ? session_id()
            : 'guest';
        return $scope . ':' . $key;
    }
}
