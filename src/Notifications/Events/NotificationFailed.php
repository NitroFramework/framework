<?php

namespace Nitro\Notifications\Events;

use Nitro\Notifications\Notification;
use Throwable;

/**
 * Fired when a channel could not deliver a notification.
 */
class NotificationFailed
{
    /**
     * @param array<string, mixed> $data Whatever the channel knows about the failure.
     */
    public function __construct(
        public object $notifiable,
        public Notification $notification,
        public string $channel,
        public ?Throwable $exception = null,
        public array $data = [],
    ) {}
}
