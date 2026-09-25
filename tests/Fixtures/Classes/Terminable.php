<?php

namespace Nitro\Tests\Fixtures\Classes;

use Closure;

class Terminable
{
    public static array $terminated = [];

    public function handle($request, Closure $next)
    {
        return $next($request);
    }

    public function terminate($request, $response): void
    {
        static::$terminated[] = $request->path();
    }
}
