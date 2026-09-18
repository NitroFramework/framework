<?php

namespace Nitro\Notifications;

use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\View\Contracts\ViewFinder;

/**
 * Binds the notification sender and channel manager.
 *
 * Also registers the `nitro::` view namespace the built-in email layout is
 * resolved through. Because the finder searches its directories in order, an
 * application can publish its own `nitro::email` and have it win.
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ChannelManager::class, function ($container) {
            return new ChannelManager($container);
        });

        $this->container->singleton('notification', function ($container) {
            return new NotificationSender($container->createOrResolve(ChannelManager::class));
        });

        $this->container->alias(NotificationSender::class, 'notification');
    }

    public function boot(): void
    {
        if (! $this->container->has(ViewFinder::class)) {
            return;
        }

        $this->container->createOrResolve(ViewFinder::class)
            ->addNamespace('nitro', __DIR__ . '/resources/views');
    }
}
