<?php

namespace Nitro\Context;

use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the context repository — the per-request bag that travels with a job
 * onto the queue and back out in a log line.
 *
 * Deferred: nothing reads it until an application puts something in it.
 */
class ContextServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [Repository::class];
    }

    public function register(): void
    {
        $this->container->singleton(Repository::class, Repository::class);
    }
}
