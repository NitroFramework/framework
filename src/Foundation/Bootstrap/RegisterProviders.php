<?php

namespace Nitro\Foundation\Bootstrap;

use Nitro\Foundation\Application;
use Nitro\View\Compiler\BladeCompiler;

/**
 * Bootstrapper: registers service providers (from the cache in production, else config).
 */
class RegisterProviders implements BootstrapperInterface
{
    public function bootstrap(Application $app): void
    {
        $cachePath = $app->paths()->cache('bootstrap.php');

        if (is_file($cachePath)) {
            $cached = require $cachePath;
            // Use the pre-merged provider list straight from cache and skip the
            // live array_merge + config lookup in registerConfiguredProviders.
            $app->registerConfiguredProviders($cached['providers'] ?? []);

            // Apply any cached directive registrations the optimize command
            // produced so the runtime ViewServiceProvider::boot doesn't have to
            // re-read config/directives.php on every request.
            if (!empty($cached['directives']) && is_array($cached['directives'])) {
                BladeCompiler::hydrateCustomDirectives($cached['directives']);
            }
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
