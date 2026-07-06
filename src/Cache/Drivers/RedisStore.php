<?php

namespace Nitro\Cache\Drivers;

use Nitro\Cache\Contracts\TaggableStoreInterface;
use Nitro\Cache\Tags\TaggedCache;
use Nitro\Cache\Tags\TagSet;

/**
 * Redis-backed cache store.
 */
class RedisStore implements TaggableStoreInterface
{
    /**
     * The Redis connection instance.
     *
     * @var object
     */
    protected object $redis;

    /**
     * @var string
     */
    protected string $prefix;

    /**
     * Allowed class names for unserialize(). true = any class, false = no
     * objects, array = explicit whitelist.
     *
     * @var bool|array
     */
    protected bool|array $allowedClasses;

    /**
     * @param object     $redis           A phpredis \Redis instance.
     * @param string     $prefix          Cache key prefix.
     * @param bool|array $allowedClasses  Whitelist for unserialize. Defaults to
     *   `true` (allow all classes) to match typical framework usage where
     *   models / paginators / DTOs get cached. To harden against object
     *   injection on a compromised Redis instance, pass an explicit list.
     */
    public function __construct(object $redis, string $prefix = '', bool|array $allowedClasses = true)
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->allowedClasses = $allowedClasses;
    }



    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        $value = $this->redis->get($this->prefix . $key);

        if ($value === false) {
            return null;
        }

        return $this->unserialize($value);
    }

    /**
     * {@inheritdoc}
     */
    public function many(array $keys): array
    {
        $prefixedKeys = array_map(fn($key) => $this->prefix . $key, $keys);

        $values = $this->redis->mGet($prefixedKeys);

        $result = [];
        foreach ($keys as $i => $key) {
            $result[$key] = ($values[$i] !== false)
                ? $this->unserialize($values[$i])
                : null;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return (bool) $this->redis->setex(
            $this->prefix . $key,
            max(1, $seconds),
            $this->serialize($value)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function putMany(array $values, int $seconds): bool
    {
        $this->redis->multi();

        foreach ($values as $key => $value) {
            $this->redis->setex(
                $this->prefix . $key,
                max(1, $seconds),
                $this->serialize($value)
            );
        }

        $results = $this->redis->exec();

        return ! in_array(false, $results, true);
    }

    /**
     * {@inheritdoc}
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        return $this->redis->incrBy($this->prefix . $key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->redis->decrBy($this->prefix . $key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function forever(string $key, mixed $value): bool
    {
        return (bool) $this->redis->set(
            $this->prefix . $key,
            $this->serialize($value)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $key): bool
    {
        return (bool) $this->redis->del($this->prefix . $key);
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): bool
    {
        // Only flush keys with our prefix, not the entire Redis DB
        if ($this->prefix) {
            $cursor = null;
            do {
                $keys = $this->redis->scan($cursor, $this->prefix . '*', 1000);
                if ($keys !== false && count($keys) > 0) {
                    $this->redis->del(...$keys);
                }
            } while ($cursor > 0);

            return true;
        }

        return (bool) $this->redis->flushDB();
    }

    /**
     * {@inheritdoc}
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * {@inheritdoc}
     */
    public function tags(array|string $names): TaggedCache
    {
        $names = is_array($names) ? $names : func_get_args();

        return new TaggedCache($this, new TagSet($this, $names));
    }

    /**
     * Get the underlying Redis connection.
     *
     * @return \Redis
     */
    public function connection(): object
    {
        return $this->redis;
    }

    /**
     * Serialize a value for storage.
     *
     * @param mixed $value
     * @return string
     */
    protected function serialize(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : serialize($value);
    }

    /**
     * Unserialize a stored value.
     *
     * @param string $value
     * @return mixed
     */
    protected function unserialize(string $value): mixed
    {
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return unserialize($value, ['allowed_classes' => $this->allowedClasses]);
    }
}
