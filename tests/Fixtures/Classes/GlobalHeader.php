<?php

namespace Nitro\Tests\Fixtures\Classes;

use Closure;

class GlobalHeader
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('X-Global', 'yes');

        return $response;
    }
}
