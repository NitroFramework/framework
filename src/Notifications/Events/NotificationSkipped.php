<?php

namespace Nitro\Notifications\Events;

use Nitro\Notifications\Notification;

/**
 * A notification was not sent on a channel, deliberately.
 */
class NotificationSkipped
{
    public function __construct(
        public object $notifiable,
        public Notification $notification,
        public string $channel,
    ) {}
}
