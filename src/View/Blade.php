<?php

namespace Nitro\View;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\PathRegistry;
use Nitro\View\Compiler\DirectiveRegistry;
use Nitro\View\Contracts\TemplateCache;
use RuntimeException;

/**
 * A standalone entry point to the Blade engine.
 *
 * Holds its own views path, extension and cache settings, and delegates
 * compilation and rendering to {@see Factory}. The container's `view` service
 * is the usual way in; this is for code that needs an instance of its own.
 */
class Blade
{
    /** Directory templates are resolved against. */
    protected string $viewsPath;

    /** Template file extension, without the leading dot. */
    protected string $extension;

    /** Directory compiled templates are written to. */
    protected string $cachePath;

    /** Whether compiled templates are reused between renders. */
    protected bool $cacheEnabled;

    /** How long a compiled template stays fresh, in seconds. */
    protected int $cacheExpiry;

    /**
     * @throws RuntimeException When the views directory is missing or unreadable.
     */
    public function __construct(
        protected TemplateCache $cache,
        protected Factory $factory,
        PathRegistry $paths,
        ConfigRepository $config
    ) {
        $this->viewsPath    = rtrim($paths->views(), '/\\');
        $this->extension    = ltrim($config->get('view.extension'), '.');
        $this->cachePath    = $paths->cache('views') ?: sys_get_temp_dir() . '/blade_cache';
        $this->cacheEnabled = (bool) $config->get('view.cache.enabled');
        $this->cacheExpiry  = (int) $config->get('view.cache.expiry');

        if (!is_dir($this->viewsPath)) {
            throw new RuntimeException("Views directory does not exist: {$this->viewsPath}");
        }

        if (!is_readable($this->viewsPath)) {
            throw new RuntimeException("Views directory is not readable: {$this->viewsPath}");
        }
    }

    /**
     * Render a template view to HTML.
     *
     * View name uses dot notation (e.g. 'pages.home' → pages/home.blade.php).
     * Delegates to the internal CompilerEngine; wraps errors in RuntimeException.
     *
     * @param string $view View name in dot notation
     * @param array  $data Variables to pass to the template
     * @return string Rendered HTML
     * @throws RuntimeException If the template is not found or compilation fails
     */
    public function render(string $view, array $data = []): string
    {
        try {
            return $this->factory->make($view, $data)->render();
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                "Failed to render view '{$view}': " . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * Render a Blade template held in a string rather than in a file.
     *
     * For the short pieces of copy that live somewhere other than the views
     * directory — an email subject line out of a config file or a database row,
     * a notification's one-line body — where wanting "Your {{ $courseTitle }}
     * certificate" to work should not mean inventing a second templating syntax
     * beside this one.
     *
     * Not for page templates: a string has no path, so nothing about it is
     * cached between calls.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderString(string $template, array $data = []): string
    {
        try {
            return $this->factory->getRenderer()->renderString($template, $data);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Failed to render template string: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * Register a custom Blade directive.
     *
     * The callback receives the directive arguments string (inside parentheses)
     * and must return the PHP code string to emit (e.g. "<?php if (\$x): ?>").
     *
     * @param string   $name     Directive name without the @ (e.g. 'myDirective' for @myDirective)
     * @param callable $callback Callable(string $args): string
     */
    public static function directive(string $name, callable $callback): void
    {
        DirectiveRegistry::directive($name, $callback);
    }

    /**
     * Register a precompiler that rewrites raw template source before Blade
     * compiles it — used to expand custom tags (e.g. <livewire:name />) into
     * directives the compiler already understands.
     *
     * @param callable $callback Callable(string $template): string
     */
    public static function precompiler(callable $callback): void
    {
        DirectiveRegistry::precompiler($callback);
    }


    /**
     * Get the current CSRF token.
     *
     * Read through the helper, which sources it from the session store rather
     * than the superglobal, so it is still there in worker mode. Falls back to
     * the superglobal where the helper is absent, such as under the CLI.
     *
     * @return string Token value, or an empty string when unavailable.
     */
    public function getCsrfToken(): string
    {
        if (function_exists('csrf_token')) {
            return csrf_token();
        }

        return $_SESSION['_csrf'] ?? '';
    }

    /**
     * Remove all compiled template files from the cache directory.
     *
     * Also invalidates opcache for those files when opcache is available.
     */
    public function clearCache(): void
    {
        $this->factory->clearCache();
    }

    /**
     * Remove the compiled cache file for a single view.
     *
     * @param string $view View name in dot notation
     */
    public function clearViewCache(string $view): void
    {
        $this->factory->clearViewCache($view);
    }

    /**
     * Turn on compiled template caching for this Blade instance.
     */
    public function enableCache(): void
    {
        $this->cacheEnabled = true;
        $this->cache->setCacheEnabled(true);
    }

    /**
     * Turn off compiled template caching (templates recompile when changed).
     */
    public function disableCache(): void
    {
        $this->cacheEnabled = false;
        $this->cache->setCacheEnabled(false);
    }

    /**
     * Set how long compiled templates are considered fresh (seconds).
     *
     * @param int $seconds TTL in seconds; 0 typically means no time-based expiry
     */
    public function setCacheExpiry(int $seconds): void
    {
        $this->cacheExpiry = $seconds;
        $this->cache->setCacheExpiry($seconds);
    }

    /**
     * Return cache statistics (enabled, path, file count, size, opcache status).
     *
     * @return array{enabled: bool, path: string, files: int, total_size: int, total_size_formatted?: string, opcache_enabled: bool, opcache_cached?: int}
     */
    public function getCacheStats(): array
    {
        return $this->factory->getCacheStats();
    }

    /**
     * Check whether a template file exists for the given view name.
     *
     * @param string $view View name in dot notation
     * @return bool True if the corresponding file exists under the views path
     */
    public function exists(string $view): bool
    {
        $templateFile = $this->viewsPath . DIRECTORY_SEPARATOR .
            str_replace('.', DIRECTORY_SEPARATOR, $view) . '.' . $this->extension;

        return file_exists($templateFile);
    }

    /**
     * Get the template file extension (e.g. 'blade.php').
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * Set the template file extension; leading dot is stripped.
     *
     * @param string $extension New extension (e.g. 'blade.php' or '.blade.php')
     */
    public function setExtension(string $extension): void
    {
        $this->extension = ltrim($extension, '.');
    }

    /**
     * Get the configured views directory path.
     */
    public function getViewsPath(): string
    {
        return $this->viewsPath;
    }

    /**
     * Set the views directory path.
     *
     * @param string $path Absolute or relative path to the views directory
     * @throws RuntimeException If the path is not an existing directory
     */
    public function setViewsPath(string $path): void
    {
        $path = rtrim($path, '/\\');

        if (!is_dir($path)) {
            throw new RuntimeException("Views directory does not exist: {$path}");
        }

        $this->viewsPath = $path;
    }

    /**
     * Get the compiled template cache directory path.
     */
    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * Check whether compiled template caching is enabled.
     */
    public function isCacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    /**
     * Get the factory this instance renders through.
     */
    public function getFactory(): Factory
    {
        return $this->factory;
    }

    /**
     * Set a section's contents, overriding whatever the template yields.
     */
    public function forceSection(string $name, string $content): void
    {
        $this->factory->forceSection($name, $content);
    }

    /**
     * Compile a view without rendering it.
     */
    public function compileOnly(string $view): void
    {
        $this->factory->compileOnly($view);
    }

    /**
     * Render one `@fragment` of a view.
     *
     * @param array<string, mixed> $data
     */
    public function renderFragment(string $view, string $fragment, array $data = []): string
    {
        return $this->factory->renderFragment($view, $fragment, $data);
    }

    /**
     * Render several fragments of a view as one response.
     *
     * @param array<int, string>   $fragments
     * @param array<string, mixed> $data
     */
    public function renderFragments(string $view, array $fragments, array $data = []): string
    {
        return $this->factory->renderFragments($view, $fragments, $data);
    }

    /**
     * Share a value with every view rendered through this instance.
     */
    public function share(string $key, mixed $value): void
    {
        $this->factory->share($key, $value);
    }

    /**
     * Register a composer against one or more view names.
     *
     * @param string|array<int, string> $views
     */
    public function composer(string|array $views, callable|string $composer): void
    {
        $this->factory->composer($views, $composer);
    }
}
