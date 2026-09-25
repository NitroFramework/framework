<?php

namespace Nitro\Tests\Fixtures\Classes;

use Closure;

class AddHeader
{
    public function handle($request, Closure $next, string $name, string $value)
    {
        $response = $next($request);
        $response->headers->set($name, $value);

        return $response;
    }
}
