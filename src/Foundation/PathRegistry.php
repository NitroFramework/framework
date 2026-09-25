<?php

namespace Nitro\Foundation;

use Nitro\Foundation\Contracts\PathRegistry as PathRegistryContract;

/**
 * Resolve the application's directories and build-cache files to absolute paths.
 *
 * Each directory derives from the base path unless pointed elsewhere. A nested
 * directory follows its parent: moving storage moves the cache, unless the cache
 * was given a path of its own.
 */
class PathRegistry implements PathRegistryContract
{
    private string $base;

    /**
     * Directories pointed somewhere other than their default, by name.
     *
     * @var array<string, string>
     */
    private array $overrides = [];

    /** Create the registry for the given application root. */
    public function __construct(string $basePath)
    {
        $this->base = rtrim($basePath, '/\\');
    }

    /** Append a subdirectory to a base path. */
    private function append(string $base, string $suffix): string
    {
        return $suffix ? $base . DIRECTORY_SEPARATOR . $suffix : $base;
    }

    /** Join path segments with this platform's separator, dropping empty ones. */
    public function join(string $base, string ...$segments): string
    {
        $parts = array_filter(
            array_map(static fn (string $s): string => trim($s, '/\\'), $segments),
            static fn (string $s): bool => $s !== '',
        );

        return $parts === []
            ? $base
            : rtrim($base, '/\\') . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * Get a directory's root: where it was pointed, or its default.
     *
     * @param string $default Relative to the base path, or absolute.
     */
    private function rootFor(string $name, string $default): string
    {
        if (isset($this->overrides[$name])) {
            return $this->overrides[$name];
        }

        return $this->isAbsolute($default) ? $default : $this->base($default);
    }

    /** Determine whether a path is absolute. */
    private function isAbsolute(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    /**
     * Point a directory somewhere other than its default.
     *
     * A relative path is taken from the base path. Set it before anything reads the path.
     */
    public function use(string $name, string $path): static
    {
        $this->overrides[$name] = $this->isAbsolute($path)
            ? rtrim($path, '/\\')
            : $this->base(trim($path, '/\\'));

        return $this;
    }

    /** Move the application root, and every directory deriving from it. */
    public function useBase(string $path): static
    {
        $this->base = rtrim($path, '/\\');

        return $this;
    }

    /** Point the app directory elsewhere. */
    public function useApp(string $path): static { return $this->use('app', $path); }

    /** Point the config directory elsewhere. */
    public function useConfig(string $path): static { return $this->use('config', $path); }

    /** Point the storage directory elsewhere. */
    public function useStorage(string $path): static { return $this->use('storage', $path); }

    /** Point the cache directory elsewhere. */
    public function useCache(string $path): static { return $this->use('cache', $path); }

    /** Point the database directory elsewhere. */
    public function useDatabase(string $path): static { return $this->use('database', $path); }

    /** Point the lang directory elsewhere. */
    public function useLang(string $path): static { return $this->use('lang', $path); }

    /** Point the public directory elsewhere. */
    public function usePublic(string $path): static { return $this->use('public', $path); }

    /** Point the resources directory elsewhere. */
    public function useResources(string $path): static { return $this->use('resources', $path); }

    /** Point the views directory elsewhere. */
    public function useViews(string $path): static { return $this->use('views', $path); }

    /** Point the bootstrap directory elsewhere. */
    public function useBootstrap(string $path): static { return $this->use('bootstrap', $path); }

    /** Get the application base path. */
    public function base(string $path = ''): string
    {
        return $this->append($this->base, $path);
    }

    /** Get the directory of the application's own classes. */
    public function app(string $path = ''): string
    {
        return $this->append($this->rootFor('app', 'app'), $path);
    }

    /** Get the directory the framework is bootstrapped from. */
    public function bootstrap(string $path = ''): string
    {
        return $this->append($this->rootFor('bootstrap', 'bootstrap'), $path);
    }

    /** Get the config directory path. */
    public function config(string $path = ''): string
    {
        return $this->append($this->rootFor('config', 'config'), $path);
    }

    /** Get the translation files directory. */
    public function lang(string $path = ''): string
    {
        return $this->append($this->rootFor('lang', 'lang'), $path);
    }

    /** Get the storage directory path. */
    public function storage(string $path = ''): string
    {
        return $this->append($this->rootFor('storage', 'storage'), $path);
    }

    /** Get the cache directory path, storage/cache unless pointed elsewhere. */
    public function cache(string $path = ''): string
    {
        return isset($this->overrides['cache'])
            ? $this->append($this->overrides['cache'], $path)
            : $this->storage($this->append('cache', $path));
    }

    /** Get the compiled configuration file. */
    public function cachedConfig(): string
    {
        return $this->cache('config.php');
    }

    /** Get the compiled route table file. */
    public function cachedRoutes(): string
    {
        return $this->cache('routes.php');
    }

    /** Get the provider list and deferred-service map `nitro optimize` writes. */
    public function cachedProviders(): string
    {
        return $this->cache('bootstrap.php');
    }

    /** Get the services manifest, which rebuilds itself when the provider list changes. */
    public function cachedServices(): string
    {
        return $this->cache('services.php');
    }

    /** Get the providers and aliases discovered from installed packages. */
    public function cachedPackages(): string
    {
        return $this->cache('packages.php');
    }

    /** Get the compiled container factories. */
    public function cachedContainer(): string
    {
        return $this->cache('container.php');
    }

    /** Get the opcache warmup bundle for compiled views. */
    public function cachedViewWarmup(): string
    {
        return $this->cache('views_warmup.php');
    }

    /** Get the cached database schema. */
    public function cachedSchema(): string
    {
        return $this->cache('schema.php');
    }

    /** Get the generated opcache preload script. */
    public function cachedPreload(): string
    {
        return $this->cache('preload.php');
    }

    /** Get the compiled event listener map. */
    public function cachedEvents(): string
    {
        return $this->cache('events.php');
    }

    /** Get the database directory path. */
    public function database(string $path = ''): string
    {
        return $this->append($this->rootFor('database', 'database'), $path);
    }

    /** Get the migrations directory path. */
    public function migrations(string $path = ''): string
    {
        return $this->database($this->append('migrations', $path));
    }

    /** Get the seeders directory path. */
    public function seeders(string $path = ''): string
    {
        return $this->database($this->append('seeders', $path));
    }

    /** Get the model factories directory path. */
    public function factories(string $path = ''): string
    {
        return $this->database($this->append('factories', $path));
    }

    /** Get the resources directory path. */
    public function resources(string $path = ''): string
    {
        return $this->append($this->rootFor('resources', 'resources'), $path);
    }

    /** Get the views directory path, resources/views unless pointed elsewhere. */
    public function views(string $path = ''): string
    {
        return isset($this->overrides['views'])
            ? $this->append($this->overrides['views'], $path)
            : $this->resources($this->append('views', $path));
    }

    /** Get the public directory path. */
    public function public(string $path = ''): string
    {
        return $this->append($this->rootFor('public', 'public'), $path);
    }
}
