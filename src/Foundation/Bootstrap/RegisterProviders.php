<?php

namespace Nitro\Foundation\Bootstrap;

use Closure;
use Illuminate\Contracts\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Foundation\ProviderManifest;

/**
 * Register the service providers, and install the caches `nitro optimize` writes.
 */
class RegisterProviders implements BootstrapperInterface
{
    /**
     * Register the providers, from the optimize cache outside debug mode, else from the manifest.
     */
    public function bootstrap(Application $app): void
    {
        $cachePath = $app->paths()->cachedProviders();

        if (!$app->isDebug() && is_file($cachePath)) {
            $cached = require $cachePath;

            $app->registerConfiguredProviders(
                $cached['providers'] ?? [],
                $cached['deferred'] ?? null,
                $cached['when'] ?? null,
            );
        } else {
            $this->registerFromManifest($app);
        }

        $containerCache = $app->paths()->cachedContainer();
        if (! $app->isDebug() && is_file($containerCache)) {
            $factories = require $containerCache;
            if (is_array($factories)) {
                $this->bindCompiledFactories($app->getContainer(), $factories);
            }
        }

        $warmup = $app->paths()->cachedViewWarmup();
        if (is_file($warmup)) {
            require_once $warmup;
        }
    }

    /**
     * Register the providers through the services manifest, rebuilt whenever the provider list changes.
     */
    private function registerFromManifest(Application $app): void
    {
        $providers = $app->configuredProviders();

        [$eager, $deferred, $when] = (new ProviderManifest($app->paths()->cachedServices()))
            ->resolve($providers, $app->getContainer());

        $app->registerConfiguredProviders($eager, $deferred, $when);
    }

    /**
     * Bind each compiled factory as the way to build its class.
     *
     * A class a provider already bound keeps that binding, and a request with constructor
     * overrides still builds by reflection.
     *
     * @param Container               $container
     * @param array<string, Closure>  $factories
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
