<?php

namespace Tests\Unit\Session;

/**
 * Stands in for a Redis connection in the session tests: records the commands
 * the handler issues and answers them from memory.
 */
class FakeRedisConnection
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var array<string, int> */
    public array $ttls = [];

    public function get(string $key): string|false
    {
        return $this->values[$key] ?? false;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    public function del(string $key): int
    {
        $existed = isset($this->values[$key]);

        unset($this->values[$key], $this->ttls[$key]);

        return $existed ? 1 : 0;
    }
}
