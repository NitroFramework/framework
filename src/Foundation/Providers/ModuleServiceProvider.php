<?php

namespace Nitro\Foundation\Providers;

use ReflectionClass;

/**
 * Base provider for self-contained modules.
 *
 * A module lives under app/Modules/{Name}/ and its provider extends this class.
 * By convention the base auto-wires everything the module ships from the module's
 * own directory, so a minimal module provider can be empty:
 *
 *   routes.php   → loadRoutesFrom()          (mounted at the module's routes)
 *   views/       → loadViewsFrom(..., slug)  (exposed as `slug::view`)
 *   migrations/  → loadMigrationsFrom()      (discovered by the migrate commands)
 *   config.php   → mergeConfigFrom(..., slug) (merged under config('slug.*'))
 *
 * The "slug" is derived from the provider's short class name
 * (BlogServiceProvider → 'blog'); override moduleSlug() to customise it.
 *
 * Wiring happens in register() so module route files are queued before the
 * router boots. Subclasses that override register() to add their own bindings
 * MUST call parent::register().
 */
class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Auto-wire the module's routes, views, migrations, and config by convention.
     * Each is applied only if the corresponding file/directory exists, so a
     * module ships just the pieces it needs.
     */
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

    /**
     * Absolute path to the module directory — the directory the concrete
     * provider class file lives in.
     */
    protected function moduleDirectory(): string
    {
        return dirname((new ReflectionClass(static::class))->getFileName());
    }

    /**
     * The module's view/config slug, derived from the provider's short class
     * name: 'BlogServiceProvider' or 'BlogModuleServiceProvider' → 'blog'.
     * Override to customise.
     */
    protected function moduleSlug(): string
    {
        $shortName = (new ReflectionClass(static::class))->getShortName();
        $base      = preg_replace('/(Module)?ServiceProvider$/', '', $shortName);

        return strtolower($base);
    }
}
