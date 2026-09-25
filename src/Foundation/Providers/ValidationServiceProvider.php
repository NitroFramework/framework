<?php

namespace Nitro\Foundation\Providers;

use Nitro\Container\Contracts\ClassResolver;
use Nitro\Validation\Factory;

/**
 * Register the validator factory.
 */
class ValidationServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [Factory::class, 'validator'];
    }

    /**
     * Register one factory for the application, so a rule added with Validator::extend() reaches every validator.
     */
    public function register(): void
    {
        $this->container->singleton(
            Factory::class,
            fn ($container): Factory => new Factory($container->resolve(ClassResolver::class)),
        );

        $this->container->alias(Factory::class, 'validator');
    }
}
