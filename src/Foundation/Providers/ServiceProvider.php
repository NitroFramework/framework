<?php

namespace Nitro\Foundation\Providers;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Database\Migration\MigrationPathRegistry;
use Nitro\Routing\RouteLoader;
use Nitro\View\Contracts\ViewEngine;

/**
 * Base Service Provider
 * 
 * All service providers extend this class to register and boot services.
 * 
 * Lifecycle:
 * 1. register() - Bind services into the container (called for ALL providers first)
 * 2. boot()     - Post-registration setup (called after ALL providers are registered)
 */
class ServiceProvider
{
    /** The container instance. */
    protected ContainerInterface $container;

    /**
     * Defer registration until one of provides() is resolved from the container.
     * Subclasses set this to true and override provides() to opt in.
     */
    protected bool $defer = false;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /** Register bindings in the container. */
    public function register(): void
    {
        // To be implemented by subclasses
    }

    /**
     * The list of services this provider binds. Used by the deferred-loading
     * path so the container knows which provider to register lazily when a
     * given abstract is first resolved.
     */
    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return $this->defer && $this->provides() !== [];
    }

    // -----------------------------------------------------------------------
    // Registration helpers (Laravel-shaped, used by modules and packages)
    // -----------------------------------------------------------------------

    /**
     * Register a routes file to be loaded, optionally under a URI prefix.
     *
     * Must be called from register() (not boot()) so the file is queued before
     * RoutingServiceProvider::boot() loads routes — this also lets `nitro
     * optimize` bake the routes into the compiled cache.
     *
     * @param string $path   Absolute path to the routes definition file.
     * @param string $prefix Optional URI prefix to mount the routes under.
     */
    protected function loadRoutesFrom(string $path, string $prefix = ''): void
    {
        $this->container->get(RouteLoader::class)->addRouteFile($path, $prefix);
    }

    /**
     * Register a view namespace so `namespace::view` resolves under $path.
     *
     * @param string $path      Absolute directory holding the namespace's views.
     * @param string $namespace Namespace hint without '::' (e.g. 'blog').
     */
    protected function loadViewsFrom(string $path, string $namespace): void
    {
        $this->container->get(ViewEngine::class)->addNamespace($namespace, $path);
    }

    /**
     * Register a directory of migrations for the migrate commands to discover.
     *
     * @param string $path Absolute path to a directory of migration files.
     */
    protected function loadMigrationsFrom(string $path): void
    {
        $this->container->get(MigrationPathRegistry::class)->add($path);
    }

    /**
     * Merge a package/module config file under $key, so the app's own config
     * overrides the module's defaults rather than the other way around.
     *
     * @param string $path Absolute path to a PHP file returning a config array.
     * @param string $key  Config key the file's array is merged under (e.g. 'blog').
     */
    protected function mergeConfigFrom(string $path, string $key): void
    {
        if (!is_file($path)) {
            return;
        }

        $moduleDefaults = require $path;
        if (!is_array($moduleDefaults)) {
            return;
        }

        $config   = $this->container->get('config');
        $existing = $config->get($key, []);

        $config->set($key, array_replace_recursive(
            $moduleDefaults,
            is_array($existing) ? $existing : []
        ));
    }
}
