<?php

namespace Nitro\Cache\Drivers;

use Nitro\Cache\Contracts\StoreInterface;

/**
 * In-memory (array) cache store — non-persistent, primarily for tests.
 */
class ArrayStore implements StoreInterface
{
    /**
     * The array of stored values.
     *
     * @var array
     */
    protected array $storage = [];

    /**
     * The array of expiration times.
     *
     * @var array
     */
    protected array $expirations = [];

    /**
     * @param string $prefix
     */
    public function __construct(
        protected string $prefix = ''
    ) {}

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        $key = $this->prefix . $key;

        if (! isset($this->storage[$key])) {
            return null;
        }

        // Check expiration
        if (isset($this->expirations[$key]) && time() >= $this->expirations[$key]) {
            $this->forgetRaw($key);
            return null;
        }

        return $this->storage[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function many(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        $key = $this->prefix . $key;

        $this->storage[$key] = $value;
        $this->expirations[$key] = time() + $seconds;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function putMany(array $values, int $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        $key = $this->prefix . $key;

        if (! isset($this->storage[$key])) {
            $this->storage[$key] = 0;
            // No expiration for newly created counters
        }

        $this->storage[$key] = ((int) $this->storage[$key]) + $value;

        return $this->storage[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * {@inheritdoc}
     */
    public function forever(string $key, mixed $value): bool
    {
        $key = $this->prefix . $key;

        $this->storage[$key] = $value;
        unset($this->expirations[$key]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $key): bool
    {
        return $this->forgetRaw($this->prefix . $key);
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): bool
    {
        $this->storage = [];
        $this->expirations = [];

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Remove a raw (already-prefixed) key.
     */
    protected function forgetRaw(string $key): bool
    {
        unset($this->storage[$key], $this->expirations[$key]);

        return true;
    }
}
