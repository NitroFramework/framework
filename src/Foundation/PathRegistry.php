<?php

namespace Nitro\Foundation;

use Nitro\Foundation\Contracts\PathRegistry as PathRegistryContract;

/**
 * Centralized path registry for the application
 *
 * Provides a single source of truth for all application directory paths.
 * All paths are derived from the base path, with methods chaining
 * into subdirectories (e.g. cache() delegates to storage('cache')).
 */
class PathRegistry implements PathRegistryContract
{
    private string $base;

    /** Initialize the registry with the application's base path. */
    public function __construct(string $basePath)
    {
        $this->base = rtrim($basePath, '/\\');
    }

    /** Append a subdirectory to a base path. */
    private function append(string $base, string $suffix): string
    {
        return $suffix ? $base . DIRECTORY_SEPARATOR . $suffix : $base;
    }

    /** Get the application base path. */
    public function base(string $path = ''): string
    {
        return $this->append($this->base, $path);
    }

    /** Get the config directory path. */
    public function config(string $path = ''): string
    {
        return $this->base($this->append('config', $path));
    }

    /** Get the storage directory path. */
    public function storage(string $path = ''): string
    {
        return $this->base($this->append('storage', $path));
    }

    /** Get the cache directory path (storage/cache). */
    public function cache(string $path = ''): string
    {
        return $this->storage($this->append('cache', $path));
    }

    // ── Build artifacts ─────────────────────────────────────────────────────
    //
    // Each compiled cache is named in exactly one place. They were spelled out
    // as string literals at every site instead — 'config.php' in five files,
    // 'packages.php' in four — so the set of readers of any one artifact could
    // only be found by grep, and renaming one meant trusting that the grep had
    // been exhaustive.

    /** The compiled configuration, written by `nitro config:cache`. */
    public function cachedConfig(): string
    {
        return $this->cache('config.php');
    }

    /** The compiled route table, written by `nitro route:cache`. */
    public function cachedRoutes(): string
    {
        return $this->cache('routes.php');
    }

    /** The pre-merged provider list and deferred-service map, from `nitro optimize`. */
    public function cachedProviders(): string
    {
        return $this->cache('bootstrap.php');
    }

    /** Providers and commands discovered from installed packages. */
    public function cachedPackages(): string
    {
        return $this->cache('packages.php');
    }

    /** AOT container factories, so autowiring costs no reflection in production. */
    public function cachedContainer(): string
    {
        return $this->cache('container.php');
    }

    /** The opcache warmup bundle for compiled views. */
    public function cachedViewWarmup(): string
    {
        return $this->cache('views_warmup.php');
    }

    /** Introspected database schema, so runtime never queries information_schema. */
    public function cachedSchema(): string
    {
        return $this->cache('schema.php');
    }

    /** The generated opcache.preload script. */
    public function cachedPreload(): string
    {
        return $this->cache('preload.php');
    }

    /** Get the database directory path. */
    public function database(string $path = ''): string
    {
        return $this->base($this->append('database', $path));
    }

    /** Get the migrations directory path (database/migrations). */
    public function migrations(string $path = ''): string
    {
        return $this->database($this->append('migrations', $path));
    }

    /** Get the seeders directory path (database/seeders). */
    public function seeders(string $path = ''): string
    {
        return $this->database($this->append('seeders', $path));
    }

    /** Get the factories directory path (database/factories). */
    public function factories(string $path = ''): string
    {
        return $this->database($this->append('factories', $path));
    }

    /** Get the resources directory path. */
    public function resources(string $path = ''): string
    {
        return $this->base($this->append('resources', $path));
    }

    /** Get the views directory path (resources/views). */
    public function views(string $path = ''): string
    {
        return $this->resources($this->append('views', $path));
    }

    /** Get the public directory path. */
    public function public(string $path = ''): string
    {
        return $this->base($this->append('public', $path));
    }
}