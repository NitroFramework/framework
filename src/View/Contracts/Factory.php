<?php

namespace Nitro\View\Contracts;

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
     * Make a value available to every view.
     */
    public function share(string $key, mixed $value): void;

    /**
     * Register a composer to run before the matching views render.
     *
     * @param string|array<int, string> $templates View names, `prefix.*`, or `*`.
     * @param callable|string           $composer  Callable, or a class name.
     */
    public function composer(string|array $templates, callable|string $composer): void;

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
