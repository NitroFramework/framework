<?php

namespace Nitro\Notifications;

use Nitro\Foundation\Providers\ServiceProvider;

/** Binds the notification sender ('notification') and channel manager. */
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
}
