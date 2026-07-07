<?php

namespace Nitro\Notifications;

/**
 * A notification. Subclasses declare their channels in via() and provide a
 * per-channel payload method: toMail(object): Message, toDatabase(object): array.
 */
abstract class Notification
{
    /** @return array<int, string> Channels to deliver on (e.g. ['mail', 'database']). */
    abstract public function via(object $notifiable): array;
}
