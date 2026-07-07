<?php

// Ignore all error 


namespace Nitro\View;

use Nitro\Foundation\PathRegistry;
use Nitro\Foundation\Contracts\ConfigRepository;

use Nitro\View\Contracts\TemplateCache;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\View\Engine\ViewFactory;
use RuntimeException;

/**
 * Blade template engine facade.
 *
 * Provides a single entry point for compiling and rendering Blade templates
 * with configurable paths, caching, and shared data. Wraps BladeCompiler and
 * ViewFactory and handles session/CSRF setup for directives like @csrf.
 *
 * RESPONSIBILITIES:
 * - Validate and hold views path, extension, and cache settings
 * - Delegate compilation to BladeCompiler and rendering to ViewFactory
 * - Manage shared data (share/getSharedData) and merge with view data in make()
 * - Initialize session and CSRF token for @csrf and auth directives
 * - Expose cache controls (enable/disable, expiry, clear, stats) and view existence check
 *
 * USAGE:
 * - Prefer using the framework's bound "view" service (ViewRenderer) for consistency.
 * - Use this class when you need a standalone Blade instance or shared data / session handling.
 *
 * @package Nitro\View
 */
class Blade
{

    // Properties for paths, extension, cache settings, and shared data
    protected string $viewsPath;
    protected string $extension;
    protected string $cachePath;
    protected bool $cacheEnabled;
    protected int $cacheExpiry;


    public function __construct(
        protected TemplateCache $cache,
        protected ViewFactory $factory,
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

        $this->initializeSession();
    }

    /**
     * Render a template view to HTML.
     *
     * View name uses dot notation (e.g. 'pages.home' → pages/home.blade.php).
     * Delegates to the internal ViewRenderer; wraps errors in RuntimeException.
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
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Failed to render view '{$view}': " . $e->getMessage(),
                0,
                $e
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
        BladeCompiler::registerCustomDirective($name, $callback);
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
        BladeCompiler::registerPrecompiler($callback);
    }

    /**
     * Ensure a session is started and a CSRF token exists.
     *
     * Skips session_start() when running in CLI. Token minting is delegated to
     * the canonical csrf_token() helper so there is a single CSPRNG source
     * shared by the view, middleware and HTMX layers — no second generator and
     * no predictable md5(uniqid()) fallback.
     */
    protected function initializeSession(): void
    {
        // The request's session is started by the kernel's request hook (through
        // the session Store), so we don't touch native session_start() here —
        // that spun up an orphaned PHP session under worker mode. Just ensure a
        // CSRF token exists; csrf_token() mints it via the Store.
        if (PHP_SAPI !== 'cli' && function_exists('csrf_token')) {
            csrf_token();
        }
    }

    /**
     * Return the current CSRF token, minting one via the canonical helper when
     * a session is active. Falls back to the raw session value (or '') when no
     * session is available (e.g. CLI), matching the previous behaviour.
     *
     * @return string Token value or empty string if unavailable
     */
    public function getCsrfToken(): string
    {
        // csrf_token() now sources the token from the framework session Store
        // (worker-safe), so we no longer gate on a native PHP session being
        // active — under worker mode there isn't one, which used to return an
        // empty token here and break CSRF.
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

    

    
    public function getFactory(): ViewFactory
    {
        return $this->factory;
    }

    public function forceSection(string $name, string $content): void
    {
        $this->factory->forceSection($name, $content);
    }

    public function compileOnly(string $view): void
    {
        $this->factory->compileOnly($view);
    }

    /**
     * Render only a named fragment from a view
     */
    // Blade.php
    public function renderFragment(string $view, string $fragment, array $data = []): string
    {
        return $this->factory->renderFragment($view, $fragment, $data);
    }

    public function renderFragments(string $view, array $fragments, array $data = []): string
    {
        return $this->factory->renderFragments($view, $fragments, $data);
    }

    public function share(string $key, mixed $value): void
    {
        $this->factory->share($key, $value);
    }

    public function composer(string|array $views, callable|string $composer): void
    {
        $this->factory->composer($views, $composer);
    }
}
