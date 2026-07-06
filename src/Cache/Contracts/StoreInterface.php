<?php

namespace Nitro\Cache\Contracts;

/**
 * Contract for a cache store backend (get/put/forget/flush).
 */
interface StoreInterface
{
    /**
     * Retrieve an item from the cache by key.
     *
     * @param string $key
     * @return mixed|null
     */
    public function get(string $key): mixed;

    /**
     * Retrieve multiple items from the cache by keys.
     *
     * @param array $keys
     * @return array
     */
    public function many(array $keys): array;

    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $seconds
     * @return bool
     */
    public function put(string $key, mixed $value, int $seconds): bool;

    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param array $values
     * @param int   $seconds
     * @return bool
     */
    public function putMany(array $values, int $seconds): bool;

    /**
     * Increment the value of an item in the cache.
     *
     * @param string $key
     * @param int    $value
     * @return int|bool
     */
    public function increment(string $key, int $value = 1): int|bool;

    /**
     * Decrement the value of an item in the cache.
     *
     * @param string $key
     * @param int    $value
     * @return int|bool
     */
    public function decrement(string $key, int $value = 1): int|bool;

    /**
     * Store an item in the cache indefinitely.
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public function forever(string $key, mixed $value): bool;

    /**
     * Remove an item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function forget(string $key): bool;

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush(): bool;

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix(): string;
}
