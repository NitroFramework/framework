<?php

namespace Nitro\Http\Middleware;

use Nitro\Cookie\CookieJar;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Flushes cookies queued on the CookieJar (via the cookie() helper) onto the
 * outgoing response. Runs inside EncryptCookies so queued cookies get encrypted.
 */
class AddQueuedCookiesToResponse
{
    public function __construct(
        protected CookieJar $cookies
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        foreach ($this->cookies->getQueuedCookies() as $cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }
}
