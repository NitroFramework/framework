<?php

namespace Nitro\Foundation\Bootstrap;

use Nitro\Foundation\Application;

/**
 * Bootstrapper: registers service providers (from the cache in production, else config).
 */
class RegisterProviders implements BootstrapperInterface
{
    public function bootstrap(Application $app): void
    {
        $cachePath = $app->paths()->cache('bootstrap.php');

        // The pre-merged provider list is a PRODUCTION optimization. In debug we
        // must always discover live, so a newly added module or provider appears
        // immediately — without a manual `optimize:clear`. Honouring a stale
        // bootstrap cache in dev silently freezes the provider list (new modules
        // never load), the same footgun RouteLoader avoids with its !app.debug
        // cache gate. So: use the cache only in production.
        if (!$app->isDebug() && is_file($cachePath)) {
            $cached = require $cachePath;
            // Use the pre-merged provider list straight from cache and skip the
            // live array_merge + config lookup in registerConfiguredProviders.
            // Directives are NOT cached (see OptimizeCommand::cacheBootstrap):
            // ViewServiceProvider::boot always registers them from
            // config/directives.php with their real, expression-aware callbacks.
            $app->registerConfiguredProviders($cached['providers'] ?? []);
        } else {
            $app->registerConfiguredProviders();
        }

        // Opcache warmup for compiled views — `nitro optimize` writes a
        // tiny bundle that opcache_compile_file()s every compiled view in
        // one pass. First request primes apache's opcache for all of
        // them; every request after gets bytecode-cached hits everywhere.
        // No-op when the bundle hasn't been generated (dev mode).
        $warmup = $app->paths()->cache('views_warmup.php');
        if (is_file($warmup)) {
            require_once $warmup;
        }
    }
}
