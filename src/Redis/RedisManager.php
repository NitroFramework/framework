<?php

namespace Nitro\Redis;

use InvalidArgumentException;
use Nitro\Redis\Connections\PhpRedisConnection;

/**
 * Resolves and caches named Redis connections from config('database.redis').
 * Calls made on the manager proxy to the default connection, so
 * Redis::set('k','v') works while Redis::connection('cache')->get('k') targets
 * a named connection. Config-driven — host/port/auth all come from config.
 *
 * @mixin PhpRedisConnection
 */
class RedisManager
{
    /** @var array<string, PhpRedisConnection> */
    protected array $connections = [];

    public function __construct(
        protected array $config = []
    ) {}

    public function connection(?string $name = null): PhpRedisConnection
    {
        $name ??= $this->config['default'] ?? 'default';

        return $this->connections[$name] ??= $this->resolve($name);
    }

    protected function resolve(string $name): PhpRedisConnection
    {
        $config = $this->config['connections'][$name]
            ?? throw new InvalidArgumentException("Redis connection [{$name}] is not configured.");

        return new PhpRedisConnection(Connector::connect($config));
    }

    /** Disconnect and forget all resolved connections. */
    public function purge(): void
    {
        foreach ($this->connections as $connection) {
            try {
                $connection->client()->close();
            } catch (\Throwable) {
                // already closed
            }
        }

        $this->connections = [];
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection()->{$method}(...$parameters);
    }
}
