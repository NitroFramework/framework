<?php

namespace Nitro\View\Contracts;

use Closure;

/**
 * The view layer's front door: everything outside it depends on this rather
 * than on the engine behind it.
 */
interface Factory
{
    /**
     * Get a view instance for the given template.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData Defaults that $data overrides.
     */
    public function make(string $template, array $data = [], array $mergeData = []): View;

    /**
     * Get the first of the given views that exists.
     *
     * @param  array<int, string>   $views
     * @param  array<string, mixed> $data
     * @param  array<string, mixed> $mergeData
     * @throws \InvalidArgumentException When none of them exist.
     */
    public function first(array $views, array $data = [], array $mergeData = []): View;

    /**
     * Get a view instance for a template addressed by absolute path.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData
     */
    public function file(string $path, array $data = [], array $mergeData = []): View;

    /**
     * Determine whether a view name resolves to a template.
     */
    public function exists(string $view): bool;

    /**
     * Make a value, or several given as an array, available to every view.
     *
     * @param array<string, mixed>|string $key
     */
    public function share(array|string $key, mixed $value = null): mixed;

    /**
     * Register a callback to run before the matching views render.
     *
     * @param  array<int, string>|string $views    View names or wildcard patterns.
     * @param  callable|string           $callback A callable, or 'Class' / 'Class@method'.
     * @return array<int, Closure>
     */
    public function composer(array|string $views, callable|string $callback): array;

    /**
     * Register a callback to run when the matching views are made.
     *
     * @param  array<int, string>|string $views
     * @return array<int, Closure>
     */
    public function creator(array|string $views, callable|string $callback): array;

    /**
     * Register directories a namespace resolves against, searched in order.
     *
     * @param string|array<int, string> $paths
     */
    public function addNamespace(string $namespace, string|array $paths): void;

    /**
     * Replace the directories a namespace resolves against.
     *
     * @param string|array<int, string> $paths
     */
    public function replaceNamespace(string $namespace, string|array $paths): void;
}
