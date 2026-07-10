<?php

namespace Nitro\Database\Query\Concerns;

use Closure;

/**
 * Opt-in query result caching, seamed into the builder.
 *
 *   Student::where('active', 1)->cache(60)->get();   // read-through, 60s
 *   DB::table('stats')->cache()->count();
 *
 * Read-through: on a miss the query runs and the result is cached; the next
 * identical query hits the cache. INVALIDATION is by per-table version stamp —
 * the cache key embeds a version counter, and every write through the builder
 * (insert/update/delete, which ALL model saves also go through) bumps that
 * counter, so old keys become unreachable and re-populate lazily on next read.
 *
 * Contract (be honest about the edges):
 *   - $ttl is the staleness backstop for writes the builder can't see (raw SQL).
 *   - Needs a SHARED cache store (redis) to invalidate across workers/servers;
 *     the array store is per-process, so a bump won't reach other workers.
 *   - Explicit opt-in per query — never a silent, model-wide auto-cache.
 */
trait CachesQueries
{
    protected ?int $cacheTtl = null;

    /** Cache this query's result for $ttl seconds (read-through, per-table versioned). */
    public function cache(?int $ttl = 60): static
    {
        $this->cacheTtl = $ttl;

        return $this;
    }

    /** Run $run through the cache when ->cache() was set and a cache store is available. */
    protected function cacheResult(string $type, Closure $run): mixed
    {
        $store = $this->cacheStore();

        if ($this->cacheTtl === null || $store === null) {
            return $run();
        }

        return $store->remember($this->cacheQueryKey($type), $this->cacheTtl, $run);
    }

    /** Versioned key: table version + SQL + bindings. A version bump orphans all old keys. */
    protected function cacheQueryKey(string $type): string
    {
        $table   = (string) ($this->from ?? '');
        $version = (int) ($this->cacheStore()?->get("qc:ver:{$table}", 0) ?? 0);

        return 'qc:' . $type . ':' . sha1(
            $table . '|' . $this->toSql() . '|' . serialize($this->getBindings()) . '|v' . $version
        );
    }

    /** Bump a table's version → invalidate every cached query for it. Called by writes. */
    public static function bumpCacheVersion(string $table): void
    {
        if ($table === '') {
            return;
        }

        $store = static::sharedCacheStore();
        $store?->increment("qc:ver:{$table}");
    }

    private function cacheStore(): ?object
    {
        return static::sharedCacheStore();
    }

    private static function sharedCacheStore(): ?object
    {
        return app()->has('cache.store') ? app('cache.store') : null;
    }
}
