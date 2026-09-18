<?php

namespace Nitro\Notifications\Events;

use Nitro\Notifications\Notification;

/**
 * Fired before a notification goes out on a channel.
 */
class NotificationSending
{
    public function __construct(
        public object $notifiable,
        public Notification $notification,
        public string $channel,
    ) {}
}
