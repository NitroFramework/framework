<?php

namespace Nitro\Foundation\Bootstrap;

use Nitro\Foundation\Application;
use Nitro\Foundation\ProviderManifest;

/**
 * Bootstrapper: registers service providers (from the cache in production, else config).
 */
class RegisterProviders implements BootstrapperInterface
{
    public function bootstrap(Application $app): void
    {
        $cachePath = $app->paths()->cachedProviders();

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
            //
            // 'deferred' is absent from caches written before it existed, and
            // null then means "no map": those providers are still in 'providers'
            // and defer themselves at runtime, as they did before.
            $app->registerConfiguredProviders(
                $cached['providers'] ?? [],
                $cached['deferred'] ?? null,
                $cached['when'] ?? null,
            );
        } else {
            $this->registerFromManifest($app);
        }

        // Install the AOT-compiled container factories so autowired controllers
        // resolve with zero reflection. Production only: gated on !debug like
        // every optimize cache, so dev always reflects and code changes take
        // effect without re-running `nitro optimize`.
        $containerCache = $app->paths()->cachedContainer();
        if (! $app->isDebug() && is_file($containerCache)) {
            $factories = require $containerCache;
            if (is_array($factories)) {
                $this->bindCompiledFactories($app->getContainer(), $factories);
            }
        }

        // Opcache warmup for compiled views — `nitro optimize` writes a
        // tiny bundle that opcache_compile_file()s every compiled view in
        // one pass. First request primes apache's opcache for all of
        // them; every request after gets bytecode-cached hits everywhere.
        // No-op when the bundle hasn't been generated (dev mode).
        $warmup = $app->paths()->cachedViewWarmup();
        if (is_file($warmup)) {
            require_once $warmup;
        }
    }

    /**
     * Register providers using the services manifest, building it if needed.
     *
     * `nitro optimize` is the fast path and stays the fast path; this is what
     * happens without it. The manifest answers "does this provider defer?" once
     * instead of on every request, which is the only way a deferred provider
     * avoids loading its class — the application has to construct one before it
     * can ask.
     *
     * Safe outside production, unlike the bootstrap cache, because the manifest
     * records the provider list it was built from and rebuilds when that list
     * changes. A new module or provider therefore appears on the next request
     * with nothing to clear.
     */
    private function registerFromManifest(Application $app): void
    {
        $providers = $app->configuredProviders();

        [$eager, $deferred, $when] = (new ProviderManifest($app->paths()->cachedServices()))
            ->resolve($providers, $app->getContainer());

        $app->registerConfiguredProviders($eager, $deferred, $when);
    }

    /**
     * Register each compiled factory as the binding for its class.
     *
     * A compiled factory is a binding — "here is how to build this class" —
     * so it is registered as one rather than kept in a map the container
     * consults ahead of its own bindings. Extenders and resolution callbacks
     * then apply to a compiled class exactly as they do to a reflected one,
     * which a separate lookup path silently skipped.
     *
     * A name a provider has already bound keeps that binding: the compiler
     * only inlines classes nothing configures, and a provider that registered
     * one after the map was written is the more current of the two.
     *
     * Constructor overrides still reflect. The factory inlines the whole
     * dependency graph, so it has nowhere to put a value passed for one
     * parameter, and answering a parameterised request with the unparameterised
     * object would be wrong rather than merely slower.
     *
     * @param \Illuminate\Contracts\Container\Container $container
     * @param array<string, \Closure>                   $factories
     */
    private function bindCompiledFactories(object $container, array $factories): void
    {
        foreach ($factories as $abstract => $factory) {
            if ($container->bound($abstract)) {
                continue;
            }

            $container->bind(
                $abstract,
                static fn (object $c, array $parameters = []): mixed => $parameters === []
                    ? $factory($c)
                    : $c->build($abstract)
            );
        }
    }
}
