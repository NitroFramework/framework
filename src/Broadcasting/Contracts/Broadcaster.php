<?php

namespace Nitro\Broadcasting\Contracts;

/**
 * Sends a message to listening clients.
 */
interface Broadcaster
{
    /**
     * Send an event out on the named channels.
     *
     * @param array<int, string>   $channels
     * @param array<string, mixed> $payload
     */
    public function broadcast(array $channels, string $event, array $payload = []): void;
}
