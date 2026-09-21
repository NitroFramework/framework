<?php

namespace Nitro\Testing;

use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the parallel-testing coordinator.
 *
 * Deferred, and deliberately so: this is the clearest case of a service that
 * should cost a production request nothing, since only a test runner ever asks
 * for it.
 */
class TestingServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [ParallelTesting::class];
    }

    public function register(): void
    {
        $this->container->singleton(ParallelTesting::class);
    }
}
