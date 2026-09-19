<?php

namespace Nitro\Cache\Events;

/**
 * Payload for cache.hit, cache.missed, cache.written and cache.forgotten.
 *
 * The key is always there; the rest depends on the event. A miss has no value
 * to report and a forget has nothing left to report, so both carry the key
 * alone — which is what a hit-rate counter or a cache-churn log needs anyway.
 */
class CacheEvent
{
    /**
     * @param string   $key   The cache key, as the application wrote it.
     * @param mixed    $value The stored value on a hit or a write; null otherwise.
     * @param int|null $ttl   Seconds the value was written for, on cache.written only.
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value = null,
        public readonly ?int $ttl = null,
    ) {}
}
