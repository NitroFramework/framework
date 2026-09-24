<?php

namespace Nitro\Redis;

use InvalidArgumentException;
use Nitro\Redis\Connections\PhpRedisConnection;
use Nitro\Redis\Contracts\Connection;

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
    /** @var array<string, Connection> */
    protected array $connections = [];

    /** @var array<string, \Closure> Drivers registered from outside. */
    protected array $customCreators = [];

    public function __construct(
        protected array $config = []
    ) {}

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->config['default'] ?? 'default';

        return $this->connections[$name] ??= $this->resolve($name);
    }

    /**
     * Build a connection by name.
     *
     * Public so an application can build one without the manager keeping it —
     * a short-lived connection for one job, say, that should not sit in the
     * cache for the rest of a worker's life.
     */
    public function resolve(string $name): Connection
    {
        $config = $this->config['connections'][$name]
            ?? throw new InvalidArgumentException("Redis connection [{$name}] is not configured.");

        $driver = $config['driver'] ?? 'phpredis';

        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($config, $name);
        }

        return new PhpRedisConnection(Connector::connect($config));
    }

    /**
     * Register a driver the framework does not ship.
     *
     * Predis, a connection over a socket, a recorder for tests — anything
     * answering {@see Connection}. Without this the driver list was one
     * hardcoded class and there was no way in.
     *
     * @param \Closure(array<string, mixed>, string): Connection $factory
     */
    public function extend(string $driver, \Closure $factory): static
    {
        $this->customCreators[$driver] = $factory;

        return $this;
    }

    /**
     * The connections resolved so far, by name.
     *
     * @return array<string, Connection>
     */
    public function connections(): array
    {
        return $this->connections;
    }

    /**
     * Put a connection in place under a name.
     *
     * What a test uses to swap a real Redis for a stand-in, without
     * configuring a driver for it.
     */
    public function setConnection(string $name, Connection $connection): static
    {
        $this->connections[$name] = $connection;

        return $this;
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
