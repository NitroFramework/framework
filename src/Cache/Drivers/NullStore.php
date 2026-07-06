<?php

namespace Nitro\Cache\Drivers;

use Nitro\Cache\Contracts\StoreInterface;

/**
 * No-op cache store used when caching is disabled; nothing is ever stored.
 */
class NullStore implements StoreInterface
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function many(array $keys): array
    {
        return array_fill_keys($keys, null);
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        return false;
    }

    public function putMany(array $values, int $seconds): bool
    {
        return false;
    }

    public function increment(string $key, int $value = 1): int|bool
    {
        return false;
    }

    public function decrement(string $key, int $value = 1): int|bool
    {
        return false;
    }

    public function forever(string $key, mixed $value): bool
    {
        return false;
    }

    public function forget(string $key): bool
    {
        return false;
    }

    public function flush(): bool
    {
        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}
