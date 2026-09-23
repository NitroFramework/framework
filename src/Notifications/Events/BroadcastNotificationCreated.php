<?php

namespace Nitro\Notifications\Events;

use Nitro\Notifications\Notification;

/**
 * A notification was pushed to the notifiable's broadcast channels.
 *
 * Its class name is also the event name a client subscribes to.
 */
class BroadcastNotificationCreated
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public object $notifiable,
        public Notification $notification,
        public array $data = [],
    ) {}
}
