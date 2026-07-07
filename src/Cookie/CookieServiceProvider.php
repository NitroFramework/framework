<?php

namespace Nitro\Cookie;

use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Binds the CookieJar as the request-scoped 'cookie' service. Cookie defaults
 * (path/domain/secure/same_site) are read from config('session') so app cookies
 * share the session cookie's scope. Scoped so the queue is fresh per request.
 */
class CookieServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->scoped('cookie', function () {
            $config = (array) config('session', []);

            return new CookieJar(
                (string) ($config['path'] ?? '/'),
                $config['domain'] ?? null,
                (bool) ($config['secure'] ?? false),
                (string) ($config['same_site'] ?? 'lax'),
            );
        });

        $this->container->alias(CookieJar::class, 'cookie');
    }
}
