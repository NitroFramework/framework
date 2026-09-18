<?php

namespace Nitro\Broadcasting\Drivers;

use Nitro\Broadcasting\Contracts\Broadcaster;
use Nitro\Support\Logger;

/**
 * Writes events to the log instead of sending them.
 *
 * For seeing what would go out while building, without a server to send to.
 */
class LogBroadcaster implements Broadcaster
{
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        Logger::info('Broadcasting [' . $event . ']', [
            'channels' => $channels,
            'payload' => $payload,
        ]);
    }
}
