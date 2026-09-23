<?php

namespace Nitro\Notifications;

use Nitro\Queue\Job;
use Throwable;

/**
 * The job that delivers a notification from the queue.
 *
 * The notifiable and the notification are serialized whole, so the rule
 * for a job's constructor arguments applies — carry ids, not models.
 */
class SendQueuedNotification extends Job
{
    /** @param array<int, string>|null $channels Override what via() names. */
    public function __construct(
        public object $notifiable,
        public Notification $notification,
        public ?array $channels = null,
    ) {}

    public function handle(): void
    {
        // sendNow, not send: this IS the queued delivery. Going back through
        // send() would look at ShouldQueue again and queue it for ever.
        \app('notification')->sendNow($this->notifiable, $this->notification, $this->channels);
    }

    /** Route to the queue the notification asks for. */
    public function queueName(): string
    {
        if ($this->notification->queue !== null) {
            return $this->notification->queue;
        }

        return method_exists($this->notification, 'queueName')
            ? $this->notification->queueName()
            : 'default';
    }

    public function connectionName(): ?string
    {
        return $this->notification->connection;
    }

    /** Name the notification in the failed-jobs table, not this wrapper. */
    public function displayName(): string
    {
        return $this->notification::class;
    }

    public function failed(Throwable $exception): void
    {
        if (method_exists($this->notification, 'failed')) {
            $this->notification->failed($exception);
        }
    }
}
