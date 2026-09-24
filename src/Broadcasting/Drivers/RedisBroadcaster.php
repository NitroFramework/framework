<?php

namespace Nitro\Broadcasting\Drivers;

use Closure;
use Nitro\Broadcasting\BroadcastException;
use Nitro\Broadcasting\Contracts\Broadcaster;
use Throwable;

/**
 * Publishes events to Redis, for a socket server to relay.
 *
 * Redis is not the thing the browser talks to — it is the hand-off. Something
 * subscribed to these channels (laravel-echo-server, soketi, a small process
 * of your own) holds the websockets and forwards what it sees here. Which
 * means no third party sees the application's traffic, and the only moving
 * part is one already in the stack.
 *
 * The message is the shape Echo expects: the event name, its data, and the
 * socket to skip.
 */
class RedisBroadcaster implements Broadcaster
{
    /**
     * @param Closure(): object $connection Yields the Redis connection to
     *   publish on. A closure rather than the manager, because publishing is
     *   the only thing this needs — and because the manager's connection type
     *   is concrete, which would make this untestable without a Redis server.
     */
    public function __construct(
        protected Closure $connection,
        protected string $prefix = '',
    ) {}

    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        if ($channels === []) {
            return;
        }

        // Pulled out rather than left in the data: it tells the relay which
        // connection to skip and is not part of what the event carries.
        $socket = $payload['socket'] ?? null;

        unset($payload['socket']);

        $message = json_encode([
            'event' => $event,
            'data' => $payload,
            'socket' => $socket,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $connection = ($this->connection)();

            foreach ($channels as $channel) {
                $connection->command('publish', [$this->prefix . $channel, $message]);
            }
        } catch (Throwable $exception) {
            throw new BroadcastException(
                'Redis could not publish the broadcast: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
