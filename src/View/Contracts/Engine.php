<?php

namespace Nitro\View\Contracts;

/**
 * Resolves view names to templates, renders them, and owns the state a
 * template accumulates while it runs.
 */
interface Engine
{
    /**
     * Render a view, including any layout it extends.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data = []): string;

    /**
     * Render a view without layout resolution.
     *
     * @param array<string, mixed> $data
     */
    public function renderPartial(string $view, array $data = []): string;

    /**
     * Compile a view without rendering it.
     */
    public function compileOnly(string $view): void;

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
     * Discard every compiled template.
     */
    public function clearCache(): void;

    /**
     * Discard the compiled form of a single view.
     */
    public function clearViewCache(string $view): void;

    /**
     * Get counts and sizes describing the compiled template cache.
     *
     * @return array<string, mixed>
     */
    public function getCacheStats(): array;

    /**
     * Determine whether a section has been defined during this render.
     */
    public function hasSection(string $name): bool;

    /**
     * Get a section's content, or the default when it was never defined.
     */
    public function yieldContent(string $section, string $default = ''): string;

    /**
     * Get every section defined during this render.
     *
     * @return array<string, string>
     */
    public function getAllSections(): array;

    /**
     * Set a section's content directly rather than by capturing output.
     */
    public function forceSection(string $name, string $content): void;

    /**
     * Render a single named fragment of a view.
     *
     * @param array<string, mixed> $data
     */
    public function renderFragment(string $view, string $fragment, array $data = []): string;

    /**
     * Render several named fragments of a view in one pass.
     *
     * @param array<int, string>   $fragments
     * @param array<string, mixed> $data
     */
    public function renderFragments(string $view, array $fragments, array $data = []): string;

    /**
     * Determine whether a view name resolves to a template.
     */
    public function viewExists(string $view): bool;

    /**
     * Render a template given as a string rather than by name.
     *
     * Blade::renderString() forwards straight to this, so it is part of what
     * an engine has to provide, not an extra the bundled one happens to have.
     *
     * @param array<string, mixed> $data
     */
    public function renderString(string $template, array $data = []): string;
}
