<?php

namespace Nitro\Concurrency;

use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Registers the Concurrency manager (per-request task fan-out).
 * Not coroutines — see the Concurrency class docblock.
 */
class ConcurrencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton('concurrency', function () {
            $driver = function_exists('config') ? (string) config('concurrency.driver', 'process') : 'process';

            return new Concurrency($driver);
        });

        $this->container->alias(Concurrency::class, 'concurrency');
    }
}
