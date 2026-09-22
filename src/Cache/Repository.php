<?php

namespace Nitro\Cache;

use Closure;
use InvalidArgumentException;
use Nitro\Cache\Contracts\Lock as LockContract;
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
    /**
     * A lock by that name.
     *
     * Returns the lock rather than running a callback under it, because a
     * lock often has to outlive the call that took it — a queued job holds
     * one for its whole run, and a second process finishes work the first
     * started. `->get($callback)` is still there for the simple case.
     *
     * @throws \BadMethodCallException When the store cannot hold a lock.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): LockContract
    {
        if (! method_exists($this->store, 'lock')) {
            throw new \BadMethodCallException(
                'The [' . $this->store::class . '] cache store does not support locking.'
            );
        }

        return $this->store->lock($name, $seconds, $owner);
    }

    /**
     * A lock somebody else already holds, by its owner token.
     *
     * @throws \BadMethodCallException When the store cannot hold a lock.
     */
    public function restoreLock(string $name, string $owner): LockContract
    {
        return $this->lock($name, 0, $owner);
    }

    /** Whether this store can tag entries. */
    public function supportsTags(): bool
    {
        return method_exists($this->store, 'tags');
    }

    /** The inverse of {@see has()}, for a condition that reads better that way. */
    public function missing(string $key): bool
    {
        return ! $this->has($key);
    }

    /**
     * Give an existing entry a new lifetime without rebuilding its value.
     *
     * A zero or negative lifetime forgets it, matching put(): asking for
     * something to live no time at all is asking for it to be gone.
     */
    public function touch(string $key, int $seconds): bool
    {
        if ($seconds <= 0) {
            return $this->forget($key);
        }

        $value = $this->get($key);

        return $value === null ? false : $this->put($key, $value, $seconds);
    }

    /** Reads as intent where rememberForever() reads as mechanism. */
    public function sear(string $key, Closure $callback): mixed
    {
        return $this->rememberForever($key, $callback);
    }

    // ─── Typed reads ──────────────────────────────────────
    //
    // A cache round-trip loses type: a driver may return '5' where an int was
    // stored, and a caller that assumes otherwise fails somewhere further on,
    // with nothing pointing back here. These fail at the read instead, naming
    // the key.

    public function integer(string $key, mixed $default = null): int
    {
        $value = $this->get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        throw new InvalidArgumentException(
            sprintf('Cache value for key [%s] must be an integer, %s given.', $key, gettype($value))
        );
    }

    public function float(string $key, mixed $default = null): float
    {
        $value = $this->get($key, $default);

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (filter_var($value, FILTER_VALIDATE_FLOAT) !== false) {
            return (float) $value;
        }

        throw new InvalidArgumentException(
            sprintf('Cache value for key [%s] must be a float, %s given.', $key, gettype($value))
        );
    }

    public function string(string $key, mixed $default = null): string
    {
        $value = $this->get($key, $default);

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Cache value for key [%s] must be a string, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    public function boolean(string $key, mixed $default = null): bool
    {
        $value = $this->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($filtered === null) {
            throw new InvalidArgumentException(
                sprintf('Cache value for key [%s] must be a boolean, %s given.', $key, gettype($value))
            );
        }

        return $filtered;
    }

    /** @return array<array-key, mixed> */
    public function array(string $key, mixed $default = null): array
    {
        $value = $this->get($key, $default);

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf('Cache value for key [%s] must be an array, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    // ─── PSR-16 ───────────────────────────────────────────
    //
    // The same operations under the names the interop standard uses, so a
    // package written against PSR-16 can be handed this repository.

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->put($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->forget($key);
    }

    public function clear(): bool
    {
        return $this->flush();
    }

    /**
     * @param  iterable<array-key, string> $keys
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        $values = $this->many(is_array($keys) ? $keys : iterator_to_array($keys));

        return array_map(static fn (mixed $value): mixed => $value ?? $default, $values);
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, ?int $ttl = null): bool
    {
        return $this->putMany(is_array($values) ? $values : iterator_to_array($values), $ttl);
    }

    /** @param iterable<array-key, string> $keys */
    public function deleteMultiple(iterable $keys): bool
    {
        $result = true;

        foreach ($keys as $key) {
            // Every key is attempted, then the worst outcome reported — a
            // failure partway through should not leave the rest in place.
            if (! $this->forget($key)) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Anything else, asked of the store directly.
     *
     * A driver may offer more than the contract names — a Redis store's own
     * connection, a counter a backend implements natively — and this keeps
     * the repository from having to enumerate what every driver can do.
     *
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store->{$method}(...$parameters);
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
