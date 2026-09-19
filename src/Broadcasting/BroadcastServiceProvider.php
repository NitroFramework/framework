<?php

namespace Nitro\Broadcasting;

use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the broadcast manager against the configured default connection.
 *
 * Deferred: an application that broadcasts nothing never reads
 * config('broadcasting') and never builds a driver.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [BroadcastManager::class];
    }

    public function register(): void
    {
        $this->container->singleton(BroadcastManager::class, fn ($container) => new BroadcastManager(
            $container,
            (string) config('broadcasting.default', 'null'),
        ));
    }
}
