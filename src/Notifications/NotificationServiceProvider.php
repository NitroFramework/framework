<?php

namespace Nitro\Notifications;

use Nitro\Foundation\Providers\ServiceProvider;

/** Binds the notification sender ('notification') and channel manager. */
class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ChannelManager::class, function ($c) {
            return new ChannelManager($c);
        });

        $this->container->singleton('notification', function ($c) {
            return new NotificationSender($c->make(ChannelManager::class));
        });

        $this->container->alias(NotificationSender::class, 'notification');
    }
}
