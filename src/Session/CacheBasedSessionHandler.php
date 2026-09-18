<?php

namespace Nitro\Session;

use Nitro\Cache\Repository;
use SessionHandlerInterface;

/**
 * Keeps sessions in a cache store.
 *
 * Any store the cache manager can build will do, so a session inherits that
 * backend's expiry and its reach across replicas. Garbage collection is the
 * store's own business, which is why {@see gc()} does nothing.
 */
class CacheBasedSessionHandler implements SessionHandlerInterface
{
    /**
     * @param int $minutes How long an entry stays in the store.
     */
    public function __construct(
        protected Repository $cache,
        protected int $minutes,
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return $this->cache->get($id, '');
    }

    public function write(string $id, string $data): bool
    {
        return (bool) $this->cache->put($id, $data, $this->minutes * 60);
    }

    public function destroy(string $id): bool
    {
        return (bool) $this->cache->forget($id);
    }

    public function gc(int $max_lifetime): int
    {
        return 0;
    }

    /**
     * Get the cache repository backing this handler.
     */
    public function getCache(): Repository
    {
        return $this->cache;
    }
}
