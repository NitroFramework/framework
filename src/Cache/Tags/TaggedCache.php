<?php

namespace Nitro\Cache\Tags;

use Nitro\Cache\Contracts\StoreInterface;

/**
 * A cache repository scoped to a set of tags so its entries can be flushed as a group.
 */
class TaggedCache
{
    /**
     * @param StoreInterface $store
     * @param TagSet         $tags
     */
    public function __construct(
        protected StoreInterface $store,
        protected TagSet $tags
    ) {}

    /**
     * Retrieve a tagged item from the cache.
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key): mixed
    {
        return $this->store->get($this->taggedKey($key));
    }

    /**
     * Store a tagged item in the cache.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $seconds
     * @return bool
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return $this->store->put($this->taggedKey($key), $value, $seconds);
    }

    /**
     * Store a tagged item in the cache indefinitely.
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->store->forever($this->taggedKey($key), $value);
    }

    /**
     * Get an item from the cache, or execute the closure and store the result.
     *
     * @param string   $key
     * @param int      $seconds
     * @param \Closure $callback
     * @return mixed
     */
    public function remember(string $key, int $seconds, \Closure $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();

        $this->put($key, $value, $seconds);

        return $value;
    }

    /**
     * Remove a tagged item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function forget(string $key): bool
    {
        return $this->store->forget($this->taggedKey($key));
    }

    /**
     * Flush all items with these tags.
     * This resets the tag versions, making all previously tagged items inaccessible.
     *
     * @return bool
     */
    public function flush(): bool
    {
        $this->tags->reset();

        return true;
    }

    /**
     * Increment a tagged cache value.
     *
     * @param string $key
     * @param int    $value
     * @return int|bool
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        return $this->store->increment($this->taggedKey($key), $value);
    }

    /**
     * Decrement a tagged cache value.
     *
     * @param string $key
     * @param int    $value
     * @return int|bool
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->store->decrement($this->taggedKey($key), $value);
    }

    /**
     * Get the namespaced key for a tagged item.
     * Prepends the tag set's namespace to create a unique key.
     *
     * @param string $key
     * @return string
     */
    protected function taggedKey(string $key): string
    {
        return sha1($this->tags->getNamespace()) . ':' . $key;
    }

    /**
     * Get the tag set instance.
     *
     * @return TagSet
     */
    public function getTags(): TagSet
    {
        return $this->tags;
    }
}
