<?php

namespace Nitro\Tests\Fixtures\Package;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class AcmeDeferredProvider extends ServiceProvider implements DeferrableProvider
{
    public static int $registered = 0;

    public function register(): void
    {
        static::$registered++;

        $this->app->singleton('acme.deferred', fn () => new \ArrayObject(['deferred' => true]));
    }

    public function provides(): array
    {
        return ['acme.deferred'];
    }
}
