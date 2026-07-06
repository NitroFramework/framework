<?php

namespace Nitro\Cache;

use Nitro\Cache\Contracts\StoreInterface;
use Nitro\Cache\Contracts\TaggableStoreInterface;
use Nitro\Cache\Tags\TaggedCache;

/**
 * The developer-facing cache API (get/put/remember/forget) over a store.
 */
class Repository
{
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

        return $value !== null ? $value : $default;
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
        foreach ($keys as $k => $v) {
            if (is_int($k)) {
                $defaults[$v] = null;
            } else {
                $defaults[$k] = $v;
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

        return $this->store->put($key, $value, $ttl);
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
        return $this->store->forget($key);
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
