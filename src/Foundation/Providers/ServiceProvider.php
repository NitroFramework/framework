<?php

namespace Nitro\Foundation\Providers;

use Nitro\Container\Contracts\ContainerInterface as Container;
use Nitro\Database\Migration\MigrationPathRegistry;
use Nitro\Routing\RouteLoader;
use Nitro\View\Contracts\ViewFinder;

/**
 * Base class for service providers.
 *
 * Every provider's register() runs before any provider's boot().
 */
class ServiceProvider
{
    /** The container instance. */
    protected Container $container;

    /**
     * Whether registration waits until one of the services in provides() is resolved.
     */
    protected bool $defer = false;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /** Register bindings in the container. */
    public function register(): void
    {
    }

    /**
     * Get the services this provider binds, for a deferred provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [];
    }

    /**
     * Get the events whose dispatch registers this deferred provider.
     *
     * @return array<int, string>
     */
    public function when(): array
    {
        return [];
    }

    /** Determine whether the provider defers its registration. */
    public function isDeferred(): bool
    {
        return $this->defer && $this->provides() !== [];
    }

    /**
     * Load a routes file under the web middleware stack.
     *
     * Call it from register(), so the file is loaded with the others. A prefix,
     * other middleware or a name prefix belongs in a Route::group() inside the file.
     *
     * @param string $path Absolute path to the routes file.
     */
    protected function loadRoutesFrom(string $path): void
    {
        $this->container->resolve(RouteLoader::class)->addRouteFile($path);
    }

    /**
     * Register a view namespace so namespace::view resolves under $path.
     *
     * @param string $path      Absolute directory holding the namespace's views.
     * @param string $namespace Namespace without '::', such as 'blog'.
     */
    protected function loadViewsFrom(string $path, string $namespace): void
    {
        $this->container->resolve(ViewFinder::class)->addNamespace($namespace, $path);
    }

    /**
     * Register a directory of migrations for the migrate commands.
     *
     * @param string $path Absolute path to a directory of migration files.
     */
    protected function loadMigrationsFrom(string $path): void
    {
        $this->container->resolve(MigrationPathRegistry::class)->add($path);
    }

    /**
     * Merge a config file under $key, with the application's own values taking precedence.
     *
     * @param string $path Absolute path to a PHP file returning a config array.
     * @param string $key  Config key the file's array is merged under, such as 'blog'.
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

        $config   = $this->container->resolve('config');
        $existing = $config->get($key, []);

        $config->set($key, array_replace_recursive(
            $moduleDefaults,
            is_array($existing) ? $existing : []
        ));
    }
}
