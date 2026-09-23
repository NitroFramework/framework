<?php

namespace Nitro\Notifications;

/**
 * A notification. Subclasses declare their channels in via() and provide a
 * per-channel payload method: toMail(object): Message, toDatabase(object): array.
 */
abstract class Notification
{
    /**
     * The identifier every channel this goes out on shares.
     *
     * Assigned by the sender, so the copy in a database and the one in
     * an inbox are recognisably the same notification.
     */
    public ?string $id = null;

    /** The locale it is rendered in, or null for the application's. */
    public ?string $locale = null;

    /** The queue connection, queue and delay, when this is queued. */
    public ?string $connection = null;

    public ?string $queue = null;

    public int $delay = 0;

    /** @return array<int, string> Channels to deliver on (e.g. ['mail', 'database']). */
    abstract public function via(object $notifiable): array;

    /** Render it in a particular locale rather than the application's. */
    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function onConnection(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function onQueue(?string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    public function delay(int $seconds): static
    {
        $this->delay = max(0, $seconds);

        return $this;
    }

    /**
     * The channels this is broadcast on.
     *
     * Only consulted by the broadcast channel; a notification that does
     * not use it need not override this.
     *
     * @return array<int, mixed>|string
     */
    public function broadcastOn(): array|string
    {
        return [];
    }
}
