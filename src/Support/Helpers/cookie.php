<?php

use Nitro\Cookie\CookieJar;
use Nitro\Http\Cookie;

if (! function_exists('cookie')) {
    /**
     * Access the cookie jar, or build a cookie.
     *
     *   cookie()                      → the CookieJar (queue via ->queue(...))
     *   cookie('name', 'value', 60)   → a Cookie (attach via response->withCookie)
     */
    function cookie(
        ?string $name = null,
        string $value = '',
        int $minutes = 0,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        bool $httpOnly = true,
    ): Cookie|CookieJar {
        /** @var CookieJar $jar */
        $jar = app('cookie');

        if ($name === null) {
            return $jar;
        }

        return $jar->make($name, $value, $minutes, $path, $domain, $secure, $httpOnly);
    }
}
