<?php

namespace Nitro\Foundation;

use Nitro\Foundation\Contracts\ConfigRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * The application configuration: the framework defaults with the application's config/*.php merged over them.
 */
class Config implements ConfigRepository
{
    private array $data = [];

    /**
     * Files under config/ that are not configuration, loaded by the layer that owns each.
     *
     * @var array<int, string>
     */
    private const NOT_CONFIG = ['routes', 'directives'];

    /**
     * Load the configuration, from the compiled cache when it is fresh.
     *
     * @param bool $ignoreCache Load from config/*.php even when a cache exists, as
     *                          `nitro optimize` must when it rebuilds the cache.
     */
    public function __construct(PathRegistry $paths, bool $ignoreCache = false)
    {
        $configPath = $paths->config();
        $cachePath = $paths->cachedConfig();

        if (!$ignoreCache && !self::runningTests() && file_exists($cachePath) && self::cacheIsFresh($cachePath, $paths->base('.env'))) {
            try {
                $cached = @require $cachePath;
                if (is_array($cached)) {
                    $this->data = $cached;
                    return;
                }
            } catch (Throwable $exception) {
            }
            @unlink($cachePath);
        }

        $this->data = require __DIR__ . '/config/defaults.php';
        $this->loadFrom($configPath);
    }

    /** Merge each config/*.php file over the framework default for its key. */
    private function loadFrom(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (glob($path . '/*.php') as $file) {
            $key = basename($file, '.php');

            if (in_array($key, self::NOT_CONFIG, true)) {
                continue;
            }

            $appValues = require $file;

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

    /** Build a repository holding exactly the given items. */
    public static function fromArray(array $data): static
    {
        $config = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $config->data = $data;
        return $config;
    }

    /**
     * Determine whether a test runner drives the process, in which case the config cache is not used.
     */
    public static function runningTests(): bool
    {
        if (defined('PHPUNIT_COMPOSER_INSTALL') || class_exists(TestCase::class, false)) {
            return true;
        }

        $entry = $_SERVER['SCRIPT_NAME'] ?? '';

        return is_string($entry) && str_contains($entry, 'phpunit');
    }

    /**
     * Determine whether a compiled config cache is newer than the .env file.
     *
     * Edits to config/*.php are not detected; run `nitro optimize` again after them.
     */
    public static function cacheIsFresh(string $cachePath, string $envFile): bool
    {
        $cacheTime = @filemtime($cachePath);
        if ($cacheTime === false) {
            return false;
        }
        if (!is_file($envFile)) {
            return true;
        }
        $envTime = @filemtime($envFile);
        return $envTime === false || $cacheTime >= $envTime;
    }
}
