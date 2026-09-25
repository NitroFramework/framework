<?php

namespace Nitro\Tests\Fixtures\Package;

use Illuminate\Support\Facades\Facade;

class Acme extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'acme.greeter';
    }
}
