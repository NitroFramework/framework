<?php

namespace Nitro\Foundation;

use Nitro\Foundation\Contracts\ConfigRepository;

/**
 * The application configuration repository.
 *
 * Loads the framework's defaults (config/defaults.php) as a base layer and
 * recursively merges the application's config/*.php on top (app wins), so every key
 * a framework internal reads is guaranteed to resolve. In production it hydrates a
 * pre-compiled cache built by `nitro optimize`; values are read with dot notation,
 * e.g. get('view.cache.expiry').
 */
class Config implements ConfigRepository
{
    private array $data = [];

    /**
     * @param bool $ignoreCache Skip the compiled config cache and load straight
     *   from config/*.php. `php nitro optimize` MUST pass true — otherwise it
     *   rebuilds the cache from the (stale) cache it just read, silently
     *   ignoring any edits to config/*.php since the last optimize.
     */
    public function __construct(PathRegistry $paths, bool $ignoreCache = false)
    {
        $configPath = $paths->config();
        $cachePath = $paths->cache('config.php');

        if (!$ignoreCache && file_exists($cachePath) && self::cacheIsFresh($cachePath, $paths->base('.env'))) {
            try {
                $cached = @require $cachePath;
                if (is_array($cached)) {
                    $this->data = $cached;
                    return;
                }
            } catch (\Throwable $e) {
                // corrupt cache - delete it and fall through to load from files
            }
            @unlink($cachePath); // delete corrupt cache
        }

        // Framework defaults are the base layer; the app's config/*.php is
        // recursively merged on top (app wins), so every key a framework
        // internal reads is guaranteed to resolve without an inline fallback.
        $this->data = require __DIR__ . '/config/defaults.php';
        $this->loadFrom($configPath);
    }

    private function loadFrom(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (glob($path . '/*.php') as $file) {
            $key = basename($file, '.php');

            if ($key === 'routes') {
                continue;
            }

            $appValues = require $file;

            // Recursively merge the app's file over the framework default for
            // this key so nested keys (e.g. auth.redirects.*) merge correctly
            // and app values win. Non-array values replace outright.
            $this->data[$key] = isset($this->data[$key])
                && is_array($this->data[$key]) && is_array($appValues)
                ? array_replace_recursive($this->data[$key], $appValues)
                : $appValues;
        }
    }

    public function has(string $key): bool
    {
        $sentinel = "\0__missing__\0";
        return $this->get($key, $sentinel) !== $sentinel;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->data;

        foreach ($keys as $segment) {
            if (!isset($value[$segment])) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->data;

        foreach ($keys as $segment) {
            if (!isset($config[$segment])) {
                $config[$segment] = [];
            }
            $config = &$config[$segment];
        }

        $config = $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    public static function fromArray(array $data): static
    {
        $config = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $config->data = $data;
        return $config;
    }

    /**
     * Is a compiled config cache still fresh relative to .env?
     *
     * A cache is stale the moment `.env` is edited after it was built (e.g.
     * `key:generate` rotating APP_KEY). Comparing mtimes lets a stale cache be
     * transparently bypassed instead of silently serving old values — the exact
     * footgun behind "I changed .env but the app didn't update". Costs one
     * filemtime on .env per request when a cache exists.
     *
     * Note: edits to config/*.php files still require `optimize:clear` /
     * re-`optimize` — we deliberately don't stat the whole config dir per
     * request. .env is the value that changes on a live box.
     */
    public static function cacheIsFresh(string $cachePath, string $envFile): bool
    {
        $cacheTime = @filemtime($cachePath);
        if ($cacheTime === false) {
            return false;
        }
        if (!is_file($envFile)) {
            return true; // nothing that can invalidate it
        }
        $envTime = @filemtime($envFile);
        return $envTime === false || $cacheTime >= $envTime;
    }
}
