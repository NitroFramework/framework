<?php

namespace Nitro\Tests\Fixtures\Package;

use Closure;

class AcmeMiddleware
{
    public function handle($request, Closure $next)
    {
        return $next($request)->header('X-Acme', 'route');
    }
}
