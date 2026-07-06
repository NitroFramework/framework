<?php

namespace Nitro\Queue;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Queue\Contracts\Queue;
use Nitro\Queue\Drivers\ArrayQueue;
use Nitro\Queue\Drivers\DatabaseQueue;
use Nitro\Queue\Drivers\SyncQueue;

/**
 * Resolves named queue connections from config/queue.php on demand,
 * caching each by name so a request that dispatches twice doesn't
 * reconstruct the driver twice.
 *
 *   $manager->connection();          // default
 *   $manager->connection('sync');    // tests
 *   $manager->connection('mail');    // alt connection
 *
 * Connection vs queue: a connection is the storage backend (database,
 * sync, array, eventually redis); a queue is a named bucket WITHIN
 * that backend (default, mail, reports). One database connection
 * holds N queues.
 *
 * The manager itself doesn't push or pop — callers ask for a
 * connection, get a Queue instance, and talk to that.
 */
class QueueManager
{
    /** @var array<string, Queue> Resolved connections, keyed by name. */
    private array $connections = [];

    public function __construct(
        private ContainerInterface $container,
        private ConfigRepository $config,
    ) {}

    public function connection(?string $name = null): Queue
    {
        $name ??= $this->config->get('queue.default');

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->resolve($name);
        }
        return $this->connections[$name];
    }

    /**
     * Build the driver for a named connection. Looked-up entries are
     * cached by connection(), so this is called at most once per name
     * per request.
     */
    private function resolve(string $name): Queue
    {
        $config = $this->config->get("queue.connections.{$name}");
        if (!is_array($config)) {
            throw new \RuntimeException(
                "Queue connection [{$name}] is not configured. "
                . "Add it under config/queue.php → connections."
            );
        }

        $driver = $config['driver'] ?? null;
        return match ($driver) {
            'sync'     => new SyncQueue($this->container),
            'array'    => new ArrayQueue(),
            'database' => new DatabaseQueue(
                table: $config['table'] ?? 'jobs',
                visibilityTimeout: (int) ($config['retry_after'] ?? 90),
            ),
            default => throw new \RuntimeException(
                "Unknown queue driver [{$driver}] for connection [{$name}]."
            ),
        };
    }

    /**
     * Replace a connection with a custom instance — used by tests to
     * swap in an ArrayQueue without rewriting config.
     */
    public function extend(string $name, Queue $queue): void
    {
        $this->connections[$name] = $queue;
    }
}
