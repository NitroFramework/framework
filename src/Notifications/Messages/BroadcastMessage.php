<?php

namespace Nitro\Notifications\Messages;

/**
 * The payload a notification broadcasts.
 */
class BroadcastMessage
{
    /** Event name broadcast under, or null for the notification's class name. */
    public ?string $event = null;

    /** Connection the broadcast is queued on, when it is queued. */
    public ?string $connection = null;

    /** Queue the broadcast is pushed to. */
    public ?string $queue = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public array $data = [],
    ) {}

    /**
     * Broadcast under a name of your own.
     */
    public function event(string $event): static
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Queue the broadcast on a given connection.
     */
    public function onConnection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Queue the broadcast on a given queue.
     */
    public function onQueue(string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }
}
