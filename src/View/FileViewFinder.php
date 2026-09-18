<?php

namespace Nitro\View;

use Nitro\View\Contracts\ViewFinder;
use RuntimeException;

/**
 * Resolves a view name to the template file behind it.
 *
 * Names are dot-notated (`orders.show`) and may carry a namespace
 * (`blog::dashboard`). Both namespaces and plain locations hold an ordered list
 * of directories, and the first match wins — which is what lets an application
 * override a view a package ships.
 */
class FileViewFinder implements ViewFinder
{
    /**
     * Memoized name-to-path resolutions, for the life of the process.
     *
     * @var array<string, string>
     */
    protected array $resolved = [];

    /**
     * Directories each namespace resolves against, in search order.
     *
     * @var array<string, array<int, string>>
     */
    protected array $hints = [];

    /**
     * Extra directories searched after the application's own views path.
     *
     * @var array<int, string>
     */
    protected array $paths = [];

    /**
     * @param string $viewsPath The application's own views directory.
     * @param string $extension Template file extension, without the dot.
     */
    public function __construct(
        protected string $viewsPath,
        protected string $extension,
    ) {
        $this->viewsPath = $this->normalisePath($viewsPath);
    }

    /**
     * Get the absolute path of the template a view name resolves to.
     *
     * A name carrying a separator may already be the file itself, which is how
     * a template outside every registered directory is addressed. A dot-notation
     * name never contains one, so the common path pays no extra stat.
     *
     * @throws RuntimeException When nothing matches, or the namespace is unknown.
     */
    public function find(string $view): string
    {
        if (isset($this->resolved[$view])) {
            return $this->resolved[$view];
        }

        if ((str_contains($view, '/') || str_contains($view, DIRECTORY_SEPARATOR)) && is_file($view)) {
            return $this->resolved[$view] = $view;
        }

        [$name, $basePaths] = $this->parse($view);

        $relative = str_replace('.', DIRECTORY_SEPARATOR, $name);
        $searched = [];

        foreach ($basePaths as $basePath) {
            $candidate = $basePath . DIRECTORY_SEPARATOR . $relative . '.' . $this->extension;

            if (file_exists($candidate)) {
                return $this->resolved[$view] = $candidate;
            }

            $searched[] = $candidate;
        }

        throw new RuntimeException(
            "Template not found: {$view}\nSearched paths:\n- " . implode("\n- ", $searched)
        );
    }

    /**
     * Determine whether a view name resolves to a template.
     */
    public function exists(string $view): bool
    {
        try {
            $this->find($view);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Split a view name into its bare name and the directories to search.
     *
     * @return array{0: string, 1: array<int, string>}
     * @throws RuntimeException When the namespace has not been registered.
     */
    protected function parse(string $view): array
    {
        $separator = strpos($view, '::');

        if ($separator === false) {
            return [$view, $this->getLocations()];
        }

        $namespace = substr($view, 0, $separator);

        if (! isset($this->hints[$namespace])) {
            throw new RuntimeException(
                "View namespace '{$namespace}::' is not registered (view '{$view}')."
            );
        }

        return [substr($view, $separator + 2), $this->hints[$namespace]];
    }

    /**
     * Register directories a namespace resolves against, searched in order.
     *
     * @param string|array<int, string> $paths
     */
    public function addNamespace(string $namespace, string|array $paths): void
    {
        $this->hints[$namespace] = array_merge(
            $this->hints[$namespace] ?? [],
            $this->normalisePaths($paths)
        );

        $this->flush();
    }

    /**
     * Register directories ahead of a namespace's existing ones.
     *
     * @param string|array<int, string> $paths
     */
    public function prependNamespace(string $namespace, string|array $paths): void
    {
        $this->hints[$namespace] = array_merge(
            $this->normalisePaths($paths),
            $this->hints[$namespace] ?? []
        );

        $this->flush();
    }

    /**
     * Replace the directories a namespace resolves against.
     *
     * @param string|array<int, string> $paths
     */
    public function replaceNamespace(string $namespace, string|array $paths): void
    {
        $this->hints[$namespace] = $this->normalisePaths($paths);

        $this->flush();
    }

    /**
     * Add a directory searched for views naming no namespace.
     */
    public function addLocation(string $path): void
    {
        $this->paths[] = $this->normalisePath($path);

        $this->flush();
    }

    /**
     * Add a directory searched before those already registered.
     */
    public function prependLocation(string $path): void
    {
        array_unshift($this->paths, $this->normalisePath($path));

        $this->flush();
    }

    /**
     * Get the directories searched for views naming no namespace, in order.
     *
     * @return array<int, string>
     */
    public function getLocations(): array
    {
        return array_merge([$this->viewsPath], $this->paths);
    }

    /**
     * Get the directories registered for each namespace.
     *
     * @return array<string, array<int, string>>
     */
    public function getHints(): array
    {
        return $this->hints;
    }

    /**
     * Discard memoized name-to-path resolutions.
     */
    public function flush(): void
    {
        $this->resolved = [];
    }

    /**
     * @param  string|array<int, string> $paths
     * @return array<int, string>
     */
    protected function normalisePaths(string|array $paths): array
    {
        return array_map([$this, 'normalisePath'], (array) $paths);
    }

    /**
     * Strip any trailing separator from a directory.
     */
    protected function normalisePath(string $path): string
    {
        return rtrim($path, "/\\");
    }
}
