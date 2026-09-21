<?php

namespace Nitro\Concurrency;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Registers the Concurrency manager (per-request task fan-out).
 * Not coroutines — see the Concurrency class docblock.
 */
class ConcurrencyServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return ['concurrency', Concurrency::class];
    }

    public function register(): void
    {
        $this->container->singleton('concurrency', function ($container) {
            $config = $container->resolve(ConfigRepository::class);

            return new Concurrency((string) $config->get('concurrency.driver', 'process'));
        });

        $this->container->alias(Concurrency::class, 'concurrency');
    }
}
