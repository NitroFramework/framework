<?php

namespace Nitro\View\Contracts;

use Closure;
use Nitro\View\View;

/**
 * Registers view composers and creators, and runs the ones a view matches.
 */
interface ViewComposerResolver
{
    /**
     * Register a callback to run before the matching views render.
     *
     * @param  array<int, string>|string $views View names or wildcard patterns.
     * @param  callable|string           $callback A callable, or 'Class' / 'Class@method'.
     * @return array<int, Closure>
     */
    public function composer(array|string $views, callable|string $callback): array;

    /**
     * Register several composers at once, as [callback => views].
     *
     * @param  array<string, array<int, string>|string> $composers
     * @return array<int, Closure>
     */
    public function composers(array $composers): array;

    /**
     * Register a callback to run when the matching views are made.
     *
     * @param  array<int, string>|string $views
     * @return array<int, Closure>
     */
    public function creator(array|string $views, callable|string $callback): array;

    /**
     * Run the composers listening for this view.
     */
    public function callComposer(View $view): void;

    /**
     * Run the creators listening for this view.
     */
    public function callCreator(View $view): void;

    /**
     * Whether anything composes or creates this view.
     */
    public function hasViewListeners(string $view): bool;
}
