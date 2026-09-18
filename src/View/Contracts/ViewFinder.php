<?php

namespace Nitro\View\Contracts;

/**
 * Resolves a view name to the template file behind it.
 */
interface ViewFinder
{
    /**
     * Get the absolute path of the template a view name resolves to.
     *
     * @throws \RuntimeException When nothing matches, or the namespace is unknown.
     */
    public function find(string $view): string;

    /**
     * Determine whether a view name resolves to a template.
     */
    public function exists(string $view): bool;

    /**
     * Register directories a namespace resolves against, searched in order.
     *
     * @param string|array<int, string> $paths
     */
    public function addNamespace(string $namespace, string|array $paths): void;

    /**
     * Register directories ahead of a namespace's existing ones.
     *
     * @param string|array<int, string> $paths
     */
    public function prependNamespace(string $namespace, string|array $paths): void;

    /**
     * Replace the directories a namespace resolves against.
     *
     * @param string|array<int, string> $paths
     */
    public function replaceNamespace(string $namespace, string|array $paths): void;

    /**
     * Add a directory searched for views naming no namespace.
     */
    public function addLocation(string $path): void;

    /**
     * Add a directory searched before those already registered.
     */
    public function prependLocation(string $path): void;

    /**
     * Get the directories searched for views naming no namespace, in order.
     *
     * @return array<int, string>
     */
    public function getLocations(): array;

    /**
     * Discard memoized name-to-path resolutions.
     */
    public function flush(): void;
}
