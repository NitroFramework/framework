<?php

namespace Nitro\Http;

use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the two factories a controller reaches for when it builds a response
 * itself rather than returning a value for the kernel to convert.
 *
 * Deferred: a route returning an array, a string or a ViewResponse never touches
 * either, and those are most routes.
 */
class HttpServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return [ResponseFactory::class, Redirector::class];
    }

    public function register(): void
    {
        $this->container->singleton(ResponseFactory::class);
        $this->container->singleton(Redirector::class);
    }
}
