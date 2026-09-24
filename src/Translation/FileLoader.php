<?php

namespace Nitro\Translation;

use Nitro\Translation\Contracts\Loader;
use RuntimeException;

/**
 * Reads lines off disk.
 *
 * Several directories may be registered, and a later one replaces keys from an
 * earlier one rather than the whole file — so an application can override a
 * single line a package ships without restating the rest of the group.
 *
 * A package registers its directory under a namespace and its lines are then
 * addressed as 'package::group.item'. The application can override any of them
 * by putting a file at lang/vendor/{namespace}/{locale}/{group}.php, which is
 * the same directory a publish command writes to.
 */
class FileLoader implements Loader
{
    /** @var array<int, string> */
    protected array $paths;

    /** @var array<int, string> */
    protected array $jsonPaths = [];

    /** @var array<string, string> Namespace => directory. */
    protected array $hints = [];

    /** @param string|array<int, string> $path */
    public function __construct(string|array $path)
    {
        $this->paths = is_string($path) ? [$path] : array_values($path);
    }

    /** @return array<string, mixed> */
    public function load(string $locale, string $group, ?string $namespace = null): array
    {
        if ($group === '*' && $namespace === '*') {
            return $this->loadJson($locale);
        }

        if ($namespace === null || $namespace === '*') {
            return $this->loadFrom($this->paths, $locale, $group);
        }

        return $this->loadNamespaced($locale, $group, $namespace);
    }

    /**
     * A package's group, with the application's overrides layered over it.
     *
     * @return array<string, mixed>
     */
    protected function loadNamespaced(string $locale, string $group, string $namespace): array
    {
        if (! isset($this->hints[$namespace])) {
            return [];
        }

        $lines = $this->loadFrom([$this->hints[$namespace]], $locale, $group);

        foreach ($this->paths as $path) {
            $file = $path . '/vendor/' . $namespace . '/' . $locale . '/' . $group . '.php';

            if (is_file($file)) {
                $lines = array_replace_recursive($lines, (array) require $file);
            }
        }

        return $lines;
    }

    /**
     * @param array<int, string> $paths
     * @return array<string, mixed>
     */
    protected function loadFrom(array $paths, string $locale, string $group): array
    {
        $lines = [];

        foreach ($paths as $path) {
            $file = $path . '/' . $locale . '/' . $group . '.php';

            if (is_file($file)) {
                $lines = array_replace_recursive($lines, (array) require $file);
            }
        }

        return $lines;
    }

    /**
     * The locale's JSON file, from every registered directory.
     *
     * Invalid JSON throws rather than translating to nothing: a file that is
     * there and unreadable is a mistake worth hearing about, where a file that
     * is absent is not.
     *
     * @return array<string, mixed>
     */
    protected function loadJson(string $locale): array
    {
        $lines = [];

        foreach (array_merge($this->jsonPaths, $this->paths) as $path) {
            $file = $path . '/' . $locale . '.json';

            if (! is_file($file)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($file), true);

            if ($decoded === null || json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("Translation file [{$file}] contains an invalid JSON structure.");
            }

            $lines = array_merge($lines, $decoded);
        }

        return $lines;
    }

    public function addNamespace(string $namespace, string $hint): void
    {
        $this->hints[$namespace] = $hint;
    }

    /** @return array<string, string> */
    public function namespaces(): array
    {
        return $this->hints;
    }

    public function addPath(string $path): void
    {
        $this->paths[] = $path;
    }

    /** @return array<int, string> */
    public function paths(): array
    {
        return $this->paths;
    }

    public function addJsonPath(string $path): void
    {
        $this->jsonPaths[] = $path;
    }

    /** @return array<int, string> */
    public function jsonPaths(): array
    {
        return $this->jsonPaths;
    }
}
