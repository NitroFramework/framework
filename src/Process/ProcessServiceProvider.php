<?php

namespace Nitro\Process;

use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the process factory used to shell out and read the result back.
 *
 * Deferred: a web request that never starts a subprocess never builds it.
 */
class ProcessServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [Factory::class];
    }

    public function register(): void
    {
        $this->container->singleton(Factory::class, Factory::class);
    }
}
