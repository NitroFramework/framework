<?php

namespace Nitro\Broadcasting;

/**
 * Lets an event choose which connection carries it.
 *
 *     class MessageSent implements ShouldBroadcast
 *     {
 *         use InteractsWithBroadcasting;
 *
 *         public function __construct()
 *         {
 *             $this->broadcastVia('redis');
 *         }
 *     }
 *
 * For an application with more than one: a high-volume event on Redis where
 * the rest go through a hosted service, say.
 */
trait InteractsWithBroadcasting
{
    /** @var array<int, string> Connections this event goes out on. */
    protected array $broadcastConnections = [];

    /**
     * Name the connection, or connections, to broadcast on.
     *
     * @param array<int, string>|string|null $connection Null means the default.
     */
    public function broadcastVia(array|string|null $connection = null): static
    {
        $this->broadcastConnections = $connection === null ? [] : (array) $connection;

        return $this;
    }

    /**
     * The connections this event goes out on. Empty means the default.
     *
     * @return array<int, string>
     */
    public function broadcastConnections(): array
    {
        return $this->broadcastConnections;
    }
}
