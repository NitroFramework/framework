<?php

namespace Nitro\View\Contracts;

/**
 * Renders views, manages sections/stacks/fragments,
 * and delegates component rendering.
 */
interface ViewEngine
{
    // Core rendering
    public function render(string $view, array $data = []): string;
    public function renderPartial(string $view, array $data = []): string;
    public function compileOnly(string $view): void;

    /** Register a view namespace so `namespace::view` resolves under $path. */
    public function addNamespace(string $namespace, string $path): void;

    // Cache delegation
    public function clearCache(): void;
    public function clearViewCache(string $view): void;
    public function getCacheStats(): array;

    // Sections (called by ViewFactory)
    public function hasSection(string $name): bool;
    public function yieldContent(string $section, string $default = ''): string;
    public function getAllSections(): array;
    public function forceSection(string $name, string $content): void;

    // Fragments
    public function renderFragment(string $view, string $fragment, array $data = []): string;
    public function renderFragments(string $view, array $fragments, array $data = []): string;

    // View existence
    public function viewExists(string $view): bool;
}