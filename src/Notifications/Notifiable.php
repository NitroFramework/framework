<?php

namespace Nitro\Notifications;

/**
 * Add to a model to give it notify(). Recipients per channel are resolved by
 * routeNotificationFor(); mail defaults to the model's `email` attribute, and a
 * routeNotificationForX() method overrides any channel.
 */
trait Notifiable
{
    public function notify(Notification $notification): void
    {
        app('notification')->send($this, $notification);
    }

    public function routeNotificationFor(string $channel): mixed
    {
        $method = 'routeNotificationFor' . ucfirst($channel);
        if (method_exists($this, $method)) {
            return $this->{$method}();
        }

        return $channel === 'mail' ? ($this->email ?? null) : null;
    }
}
