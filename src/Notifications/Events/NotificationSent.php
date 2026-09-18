<?php

namespace Nitro\Notifications\Events;

use Nitro\Notifications\Notification;

/**
 * Fired once a notification has gone out on a channel.
 */
class NotificationSent
{
    public function __construct(
        public object $notifiable,
        public Notification $notification,
        public string $channel,
    ) {}
}
