<?php

namespace Nitro\Notifications;

use InvalidArgumentException;

/**
 * A recipient given by address rather than by model.
 *
 * For notifying somebody the application has no record of — an address typed
 * into a form, an on-call inbox from config — without inventing a user to
 * hang the notification off.
 *
 *   Notification::route('mail', 'ops@example.com')->notify(new Alert());
 */
class AnonymousNotifiable
{
    /**
     * Where to deliver, per channel.
     *
     * @var array<string, mixed>
     */
    public array $routes = [];

    /**
     * Add a route for a channel.
     *
     * @throws InvalidArgumentException When routing the database channel, which
     *   has no row to write against without a model.
     */
    public function route(string $channel, mixed $route): static
    {
        if ($channel === 'database') {
            throw new InvalidArgumentException(
                'The database channel needs a notifiable model; it cannot be routed anonymously.'
            );
        }

        $this->routes[$channel] = $route;

        return $this;
    }

    /**
     * Send a notification to this recipient.
     */
    public function notify(Notification $notification): void
    {
        app('notification')->send($this, $notification);
    }

    /**
     * Send a notification immediately, bypassing the queue.
     */
    public function notifyNow(Notification $notification): void
    {
        app('notification')->sendNow($this, $notification);
    }

    /**
     * Get the route for a channel.
     */
    public function routeNotificationFor(string $channel): mixed
    {
        return $this->routes[$channel] ?? null;
    }

    /**
     * Anonymous recipients have no identifier, which the database channel
     * relies on — hence the guard in {@see route()}.
     */
    public function getKey(): mixed
    {
        return null;
    }
}
