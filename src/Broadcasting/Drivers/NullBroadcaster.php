<?php

namespace Nitro\Broadcasting\Drivers;

use Nitro\Broadcasting\Contracts\Broadcaster;

/**
 * Accepts events and sends them nowhere.
 *
 * The default, so an application that broadcasts nothing needs no
 * configuration and a test never reaches the network.
 */
class NullBroadcaster implements Broadcaster
{
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
    }
}
