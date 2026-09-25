<?php

namespace Nitro\Foundation\Providers;

use ReflectionClass;

/**
 * Base provider for a module under app/Modules/{Name}, wiring what the module ships.
 *
 * Loaded when present in the module directory:
 *
 *   routes.php   routes, under the web stack
 *   views/       views, as slug::view
 *   migrations/  migrations, found by the migrate commands
 *   config.php   configuration, merged under config('slug.*')
 *
 * A subclass that overrides register() must call parent::register().
 */
class ModuleServiceProvider extends ServiceProvider
{
    /** Load the module's routes, configuration, views and migrations. */
    public function register(): void
    {
        $directory = $this->moduleDirectory();
        $slug      = $this->moduleSlug();

        $routes = $directory . DIRECTORY_SEPARATOR . 'routes.php';
        if (is_file($routes)) {
            $this->loadRoutesFrom($routes);
        }

        $config = $directory . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($config)) {
            $this->mergeConfigFrom($config, $slug);
        }

        $views = $directory . DIRECTORY_SEPARATOR . 'views';
        if (is_dir($views)) {
            $this->loadViewsFrom($views, $slug);
        }

        $migrations = $directory . DIRECTORY_SEPARATOR . 'migrations';
        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }
    }

    /** Get the directory the module's provider class lives in. */
    protected function moduleDirectory(): string
    {
        return dirname((new ReflectionClass(static::class))->getFileName());
    }

    /**
     * Get the module's view and config slug: BlogServiceProvider becomes 'blog'.
     */
    protected function moduleSlug(): string
    {
        $shortName = (new ReflectionClass(static::class))->getShortName();
        $base      = preg_replace('/(Module)?ServiceProvider$/', '', $shortName);

        return strtolower($base);
    }
}
