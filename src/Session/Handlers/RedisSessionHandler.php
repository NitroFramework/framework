<?php

namespace Nitro\Session\Handlers;

use SessionHandlerInterface;

/**
 * Redis-backed session handler.
 *
 * Every payload is written with an expiry equal to the session lifetime, so
 * Redis evicts idle sessions itself and {@see gc()} has nothing to do. Sessions
 * live outside the container's filesystem, which is what makes them survive a
 * deployment and stay consistent across replicas.
 */
class RedisSessionHandler implements SessionHandlerInterface
{
    /**
     * @param object $connection A phpredis client or a connection proxying to one.
     * @param int    $minutes    Idle lifetime; also the key's TTL.
     * @param string $prefix     Prepended to every key.
     */
    public function __construct(
        private object $connection,
        private int $minutes = 120,
        private string $prefix = 'nitro:session:',
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $payload = $this->connection->get($this->prefix . $id);

        return is_string($payload) ? $payload : '';
    }

    public function write(string $id, string $data): bool
    {
        return (bool) $this->connection->setex(
            $this->prefix . $id,
            max(1, $this->minutes * 60),
            $data,
        );
    }

    public function destroy(string $id): bool
    {
        $this->connection->del($this->prefix . $id);

        return true;
    }

    /**
     * No-op: Redis expires the keys.
     *
     * Kept so the handler satisfies the interface and a sweep triggered by the
     * session lottery costs nothing on this driver.
     */
    public function gc(int $max_lifetime, int $limit = 0): int|false
    {
        return 0;
    }
}
