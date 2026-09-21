<?php

namespace Nitro\Broadcasting;

use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the broadcast manager.
 *
 * No factory: both of the manager's dependencies are types the container can
 * resolve, so it autowires. It asks for a
 * {@see \Nitro\Container\Contracts\ClassResolver} rather than the container —
 * it instantiates channel authorisers named by class string, and that is the
 * only container capability it needs. Principle of least privilege.
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
        $this->container->singleton(BroadcastManager::class);
    }
}
