<?php

namespace Nitro\Tests\Fixtures\Package;

use Closure;

class AcmeGlobalMiddleware
{
    public function handle($request, Closure $next)
    {
        return $next($request)->header('X-Acme-Global', 'yes');
    }
}
