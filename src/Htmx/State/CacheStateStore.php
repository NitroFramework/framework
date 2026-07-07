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
     * Prefix the key with a per-user scope — the session id, read through the
     * canonical nitro_session() seam. Using PHP's raw session_id() broke under
     * worker mode: with a non-native (file/redis) store there is no PHP session,
     * so session_id() returned '' and every user collapsed into the shared
     * "guest" bucket (cross-user state leak). Falls back to "guest" only when no
     * session is available at all.
     */
    private function scopedKey(string $key): string
    {
        $scope = 'guest';
        try {
            $id = nitro_session()->getId();
            if ($id !== '') {
                $scope = $id;
            }
        } catch (\Throwable) {
            // no session bound — shared guest bucket
        }
        return $scope . ':' . $key;
    }
}
