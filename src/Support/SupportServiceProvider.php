<?php

namespace Nitro\Support;

use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the leaf utilities of the Support layer: hashing, dates, pipelines.
 *
 * They were bound by the Application itself, which meant the composition root
 * carried construction knowledge for three subsystems it has no other reason to
 * know about — and bound all three on every request whether or not anything
 * hashed a password. Deferred here, an application that never calls them never
 * builds them, and `nitro optimize` keeps even this class out of boot.
 */
class SupportServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [Hash::class, DateFactory::class, Pipeline::class];
    }

    public function register(): void
    {
        $this->container->singleton(Hash::class);
        $this->container->singleton(DateFactory::class);

        /*
         * Bound rather than shared: a pipeline holds the value travelling
         * through it, so two callers sharing one would see each other's.
         */
        $this->container->bind(Pipeline::class, Pipeline::class);
    }
}
