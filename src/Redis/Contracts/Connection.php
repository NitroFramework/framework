<?php

namespace Nitro\Redis\Contracts;

/**
 * What the framework needs of a Redis connection.
 *
 * Two methods, because that is all anything asks for: run a command, and hand
 * over the client for the rare case that needs it directly. The queue, the
 * cache store, the session handler and the broadcaster all go through
 * command() or the magic proxy.
 *
 * It exists so {@see \Nitro\Redis\RedisManager::extend()} can mean something.
 * Without it the manager's connection() returned one concrete class, so a
 * driver of your own had nowhere to be returned from — the broadcaster had to
 * take a closure rather than a manager for exactly that reason.
 */
interface Connection
{
    /**
     * Run a command by name.
     *
     * @param array<int, mixed> $parameters
     */
    public function command(string $method, array $parameters = []): mixed;

    /** The underlying client, whatever it is. */
    public function client(): object;
}
