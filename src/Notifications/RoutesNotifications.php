<?php

namespace Nitro\Notifications;

/**
 * Where a notifiable receives notifications, and how it is sent one.
 *
 * Separate from {@see Notifiable} so a class can be notified without
 * also taking the database relation — an address book entry has an
 * email and no notifications table row of its own.
 */
trait RoutesNotifications
{
    /** Send a notification to this notifiable. */
    public function notify(Notification $notification): void
    {
        \app('notification')->send($this, $notification);
    }

    /** Send it in this process, whatever it asked for. */
    public function notifyNow(Notification $notification, ?array $channels = null): void
    {
        \app('notification')->sendNow($this, $notification, $channels);
    }

    /**
     * Where this notifiable is reached on a channel.
     *
     * A routeNotificationForMail() on the model answers for 'mail', and
     * so on for any channel; without one, mail falls back to an email
     * attribute, which is what a user model has.
     */
    public function routeNotificationFor(string $channel): mixed
    {
        $method = 'routeNotificationFor' . ucfirst($channel);

        if (method_exists($this, $method)) {
            return $this->{$method}();
        }

        return $channel === 'mail' ? ($this->email ?? null) : null;
    }
}
