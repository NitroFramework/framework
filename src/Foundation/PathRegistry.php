<?php

namespace Nitro\Foundation;

use Nitro\Foundation\Contracts\PathRegistry as PathRegistryContract;

/**
 * Centralized path registry for the application
 *
 * Provides a single source of truth for all application directory paths.
 * Each is derived from the base path by default, and each can be pointed
 * somewhere else — an application whose layout is not the conventional one has
 * to be able to say so, and a package that keeps its config or its
 * translations elsewhere has nowhere else to say it.
 *
 * A directory that nests inside another follows it: moving storage moves the
 * cache with it, unless the cache was itself given a path.
 */
class PathRegistry implements PathRegistryContract
{
    private string $base;

    /**
     * Directories pointed somewhere other than their default.
     *
     * Absent means "derive it", which is what keeps storage() and cache()
     * related until someone deliberately separates them.
     *
     * @var array<string, string>
     */
    private array $overrides = [];

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

    /**
     * Join path segments with this platform's separator.
     *
     * Empty segments fall away, so join($base, '', 'views') does not leave a
     * doubled separator behind.
     */
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
     * A directory's root: where it was pointed, or where it derives from.
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

    /** Whether a path names a location by itself rather than relative to another. */
    private function isAbsolute(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    /**
     * Point a directory somewhere else.
     *
     * A relative path is taken from the base path, an absolute one as given.
     * Call it before anything reads the path — a bootstrapper, not a request.
     */
    public function use(string $name, string $path): static
    {
        $this->overrides[$name] = $this->isAbsolute($path)
            ? rtrim($path, '/\\')
            : $this->base(trim($path, '/\\'));

        return $this;
    }

    /** Move the application root, and everything deriving from it with it. */
    public function useBase(string $path): static
    {
        $this->base = rtrim($path, '/\\');

        return $this;
    }

    public function useApp(string $path): static { return $this->use('app', $path); }
    public function useConfig(string $path): static { return $this->use('config', $path); }
    public function useStorage(string $path): static { return $this->use('storage', $path); }
    public function useCache(string $path): static { return $this->use('cache', $path); }
    public function useDatabase(string $path): static { return $this->use('database', $path); }
    public function useLang(string $path): static { return $this->use('lang', $path); }
    public function usePublic(string $path): static { return $this->use('public', $path); }
    public function useResources(string $path): static { return $this->use('resources', $path); }
    public function useViews(string $path): static { return $this->use('views', $path); }
    public function useBootstrap(string $path): static { return $this->use('bootstrap', $path); }

    /** Get the application base path. */
    public function base(string $path = ''): string
    {
        return $this->append($this->base, $path);
    }

    /** Where the application's own classes live. */
    public function app(string $path = ''): string
    {
        return $this->append($this->rootFor('app', 'app'), $path);
    }

    /** Where the framework is bootstrapped from. */
    public function bootstrap(string $path = ''): string
    {
        return $this->append($this->rootFor('bootstrap', 'bootstrap'), $path);
    }

    /** Get the config directory path. */
    public function config(string $path = ''): string
    {
        return $this->append($this->rootFor('config', 'config'), $path);
    }

    /** Where translation files live. */
    public function lang(string $path = ''): string
    {
        return $this->append($this->rootFor('lang', 'lang'), $path);
    }

    /** Get the storage directory path. */
    public function storage(string $path = ''): string
    {
        return $this->append($this->rootFor('storage', 'storage'), $path);
    }

    /**
     * Get the cache directory path (storage/cache).
     *
     * Derived from storage unless pointed elsewhere, so moving storage moves
     * this too — which is what an application relocating its writable
     * directory means by it.
     */
    public function cache(string $path = ''): string
    {
        return isset($this->overrides['cache'])
            ? $this->append($this->overrides['cache'], $path)
            : $this->storage($this->append('cache', $path));
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

    /**
     * Which providers are eager and which defer, written on first boot.
     *
     * Separate from bootstrap.php because this one answers a question about the
     * provider classes alone, and rebuilds itself when that list changes — so
     * it is safe to keep without running `nitro optimize`.
     */
    public function cachedServices(): string
    {
        return $this->cache('services.php');
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

    /** The compiled event listener map. */
    public function cachedEvents(): string
    {
        return $this->cache('events.php');
    }

    /** Get the database directory path. */
    public function database(string $path = ''): string
    {
        return $this->append($this->rootFor('database', 'database'), $path);
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
        return $this->append($this->rootFor('resources', 'resources'), $path);
    }

    /** Get the views directory path (resources/views). */
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
