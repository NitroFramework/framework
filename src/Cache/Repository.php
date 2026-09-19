<?php

namespace Nitro\Cache;

use Nitro\Cache\Contracts\StoreInterface;
use Nitro\Cache\Contracts\TaggableStoreInterface;
use Nitro\Cache\Tags\TaggedCache;
use Nitro\Events\Concerns\DispatchesEvents;
use Nitro\Events\Contracts\ReceivesDispatcher;
use Nitro\Cache\Events\CacheEvent;
use Nitro\Cache\Events\CacheEvents;

/**
 * The developer-facing cache API (get/put/remember/forget) over a store.
 */
class Repository implements ReceivesDispatcher
{
    use DispatchesEvents;

    /**
     * @param StoreInterface $store
     */
    public function __construct(
        protected StoreInterface $store
    ) {}

    // -------------------------------------------------------------------------
    // Core Operations
    // -------------------------------------------------------------------------

    /**
     * Determine if a key exists in the cache.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Retrieve an item, returning a default if not found.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->store->get($key);

        /**
         * Emit point — cache.hit / cache.missed
         *
         * Once per lookup, on every read the application makes, so this is a
         * hot path. Exactly one of the pair fires. A missing key and a stored
         * null are the same thing here, which is the store contract, not a
         * decision taken at this line.
         * Payload: {@see CacheEvent}.
         */
        if ($value !== null) {
            $this->eventLazy(CacheEvents::HIT, fn (): CacheEvent => new CacheEvent($key, $value));

            return $value;
        }

        $this->eventLazy(CacheEvents::MISSED, fn (): CacheEvent => new CacheEvent($key));

        return $default;
    }

    /**
     * Retrieve multiple items. Missing keys get their default.
     *
     * @param array $keys  ['key1' => 'default1', 'key2' => 'default2'] or ['key1', 'key2']
     * @return array
     */
    public function many(array $keys): array
    {
        // Normalize: if numeric keys, use null as default
        $defaults = [];
        foreach ($keys as $keyOrIndex => $keyOrDefault) {
            if (is_int($keyOrIndex)) {
                $defaults[$keyOrDefault] = null;
            } else {
                $defaults[$keyOrIndex] = $keyOrDefault;
            }
        }

        $results = $this->store->many(array_keys($defaults));

        // Fill in defaults for missing keys
        foreach ($results as $key => $value) {
            if ($value === null) {
                $results[$key] = $defaults[$key] ?? null;
            }
        }

        return $results;
    }

    /**
     * Store an item in the cache.
     *
     * @param string   $key
     * @param mixed    $value
     * @param int|null $ttl  Seconds, null = default TTL
     * @return bool
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        // null TTL = store forever (Laravel / PSR-16 contract); a non-positive
        // TTL means "expire now" → delete. This also matches the HTMX state
        // store, whose config documents `ttl: null` as "no expiry".
        if ($ttl === null) {
            return $this->forever($key, $value);
        }

        if ($ttl <= 0) {
            return $this->forget($key);
        }

        $stored = $this->store->put($key, $value, $ttl);

        /**
         * Emit point — cache.written
         *
         * After a successful write with a TTL. put() with a null TTL routes to
         * forever() and a non-positive one to forget(), so those raise their
         * own events rather than this one. A failed write raises nothing.
         * Payload: {@see CacheEvent}.
         */
        if ($stored) {
            $this->eventLazy(
                CacheEvents::WRITTEN,
                fn (): CacheEvent => new CacheEvent($key, $value, $ttl),
            );
        }

        return $stored;
    }

    /**
     * Store a value only when the key is absent.
     *
     * Atomic in the store, so it can be used as a lock: the caller that gets
     * true owns it until the entry expires.
     *
     * @param int|null $ttl Seconds to hold it; null holds it indefinitely.
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->store->add($key, $value, $ttl ?? (60 * 60 * 24 * 365));
    }

    /**
     * Run the callback while holding a named lock, or return null.
     *
     * The lock is released whatever the callback does, so a throw cannot
     * strand it; if the process dies the entry expires on its own.
     *
     * @param  int      $seconds How long the lock may be held before it lapses.
     * @return mixed The callback's return value, or null when the lock was taken.
     */
    public function lock(string $key, int $seconds, \Closure $callback): mixed
    {
        if (! $this->add('lock:' . $key, 1, $seconds)) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->forget('lock:' . $key);
        }
    }

    /**
     * Store multiple items in the cache.
     *
     * @param array    $values
     * @param int|null $ttl
     * @return bool
     */
    public function putMany(array $values, ?int $ttl = null): bool
    {
        // null = forever; non-positive = expire now (delete). Mirrors put() so
        // a store-wide default can't leave stale values masquerading as cached.
        if ($ttl === null) {
            $ok = true;
            foreach ($values as $key => $value) {
                $ok = $this->forever($key, $value) && $ok;
            }
            return $ok;
        }

        if ($ttl <= 0) {
            $ok = true;
            foreach (array_keys($values) as $key) {
                $ok = $this->forget($key) && $ok;
            }
            return $ok;
        }

        return $this->store->putMany($values, $ttl);
    }

    /**
     * Store an item forever.
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->store->forever($key, $value);
    }

    // -------------------------------------------------------------------------
    // Remember (Get or Set)
    // -------------------------------------------------------------------------

    /**
     * Get an item from the cache, or execute the callback and store the result.
     *
     * @param string   $key
     * @param int|null $ttl
     * @param \Closure $callback
     * @return mixed
     */
    public function remember(string $key, ?int $ttl, \Closure $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();

        $this->put($key, $value, $ttl);

        return $value;
    }

    /**
     * Get an item from the cache, or execute the callback and store forever.
     *
     * @param string   $key
     * @param \Closure $callback
     * @return mixed
     */
    public function rememberForever(string $key, \Closure $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();

        $this->forever($key, $value);

        return $value;
    }

    /**
     * Get an item and then delete it.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);

        $this->forget($key);

        return $value;
    }

    // -------------------------------------------------------------------------
    // Increment / Decrement
    // -------------------------------------------------------------------------

    /**
     * Increment a cached value.
     *
     * @param string $key
     * @param int    $value
     * @return int|bool
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        return $this->store->increment($key, $value);
    }

    /**
     * Decrement a cached value.
     *
     * @param string $key
     * @param int    $value
     * @return int|bool
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->store->decrement($key, $value);
    }

    // -------------------------------------------------------------------------
    // Removal
    // -------------------------------------------------------------------------

    /**
     * Remove an item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function forget(string $key): bool
    {
        $forgotten = $this->store->forget($key);

        /**
         * Emit point — cache.forgotten
         *
         * After a key was removed. Also fires for put() with a non-positive
         * TTL, which means "expire now" and is a delete. Forgetting a key that
         * was never there raises nothing.
         * Payload: {@see CacheEvent}.
         */
        if ($forgotten) {
            $this->eventLazy(CacheEvents::FORGOTTEN, fn (): CacheEvent => new CacheEvent($key));
        }

        return $forgotten;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush(): bool
    {
        return $this->store->flush();
    }

    // -------------------------------------------------------------------------
    // Tags
    // -------------------------------------------------------------------------

    /**
     * Begin a tag operation if the store supports it.
     *
     * @param array|string $names
     * @return TaggedCache
     * @throws \RuntimeException
     */
    public function tags(array|string $names): TaggedCache
    {
        if (! $this->store instanceof TaggableStoreInterface) {
            throw new \RuntimeException(
                sprintf(
                    'Cache store [%s] does not support tagging.',
                    get_class($this->store)
                )
            );
        }

        return $this->store->tags($names);
    }

    // -------------------------------------------------------------------------
    // Access
    // -------------------------------------------------------------------------

    /**
     * Get the underlying store implementation.
     *
     * @return StoreInterface
     */
    public function getStore(): StoreInterface
    {
        return $this->store;
    }
}
