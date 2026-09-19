<?php

namespace Nitro\Cache\Events;

/**
 * Events the cache layer raises.
 *
 * All four carry a {@see CacheEvent}. The hit/miss pair fires once per lookup
 * and exactly one of the two happens, which is what makes a hit-rate counter
 * a two-line listener.
 */
class CacheEvents
{
    /** A key was found. The payload carries its value. */
    const HIT = 'cache.hit';

    /** A key was absent, or held null — the store treats those the same. */
    const MISSED = 'cache.missed';

    /** A value was stored with a TTL. Writing forever raises nothing. */
    const WRITTEN = 'cache.written';

    /** A key was removed, including by a write with a non-positive TTL. */
    const FORGOTTEN = 'cache.forgotten';
}
