<?php

namespace Nitro\Tests\Fixtures\Package;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class AcmeWhenProvider extends ServiceProvider implements DeferrableProvider
{
    public static int $registered = 0;

    public function register(): void
    {
        static::$registered++;
    }

    public function provides(): array
    {
        return [];
    }

    public function when(): array
    {
        return [AcmeWhenEvent::class];
    }
}
