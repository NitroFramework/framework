<?php

namespace Nitro\Database\Migration;

/**
 * Registry of directories to scan for migration files.
 *
 * The application's default migrations directory is seeded at boot; module
 * providers add their own via ServiceProvider::loadMigrationsFrom(). The migrate
 * console commands read all() to discover migrations across the application and
 * every registered module, so a module ships its schema alongside its code.
 */
class MigrationPathRegistry
{
    /** @var array<int, string> Absolute migration directories, in registration order. */
    private array $paths = [];

    /**
     * Register a migrations directory. No-op if the path is already registered,
     * so repeated provider boots don't duplicate it.
     *
     * @param string $path Absolute path to a directory containing migration files.
     */
    public function add(string $path): void
    {
        $path = rtrim($path, '/\\');

        if ($path !== '' && !in_array($path, $this->paths, true)) {
            $this->paths[] = $path;
        }
    }

    /**
     * All registered migration directories, in registration order (application
     * default first, then modules as they register).
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->paths;
    }
}
