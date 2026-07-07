<?php

namespace Nitro\Redis;

use InvalidArgumentException;
use Nitro\Redis\Connections\PhpRedisConnection;
use RuntimeException;

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

        if (! extension_loaded('redis')) {
            throw new RuntimeException('The phpredis extension is required for Redis connections.');
        }

        $client = new \Redis();

        $connect = ($config['persistent'] ?? false) ? 'pconnect' : 'connect';
        $client->{$connect}(
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 6379),
            (float) ($config['timeout'] ?? 0.0),
        );

        if (! empty($config['password'])) {
            $client->auth($config['password']);
        }
        if (isset($config['database'])) {
            $client->select((int) $config['database']);
        }
        if (! empty($config['prefix'])) {
            $client->setOption(\Redis::OPT_PREFIX, (string) $config['prefix']);
        }

        return new PhpRedisConnection($client);
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
