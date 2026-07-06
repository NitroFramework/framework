<?php

namespace Nitro\Foundation;

/**
 * Centralized path registry for the application
 * 
 * Provides a single source of truth for all application directory paths.
 * All paths are derived from the base path, with methods chaining
 * into subdirectories (e.g. cache() delegates to storage('cache')).
 */
class PathRegistry
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