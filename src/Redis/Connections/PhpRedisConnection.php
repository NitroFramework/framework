<?php

namespace Nitro\Redis\Connections;

/**
 * A single Redis connection backed by the phpredis extension. Commands are
 * proxied to the underlying \Redis client, so any phpredis method works:
 * $conn->set('k','v'), $conn->hset(...), $conn->expire(...), etc.
 *
 * @mixin \Redis
 */
class PhpRedisConnection
{
    public function __construct(
        protected \Redis $client
    ) {}

    /** The underlying phpredis client. */
    public function client(): \Redis
    {
        return $this->client;
    }

    /** Run a command by name. */
    public function command(string $method, array $parameters = []): mixed
    {
        return $this->client->{$method}(...$parameters);
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->client->{$method}(...$parameters);
    }
}
