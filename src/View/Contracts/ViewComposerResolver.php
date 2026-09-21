<?php

namespace Nitro\View\Contracts;

use Nitro\Container\Contracts\ClassResolver;
use Nitro\View\View;

/**
 * Registers view composers and runs the ones a view matches.
 */
interface ViewComposerResolver
{
    /**
     * Register a composer against one or more view names or patterns.
     *
     * @param string|array<int, string> $templates
     * @param callable|string           $composer Callable, or a class resolved when it fires.
     */
    public function register(string|array $templates, callable|string $composer): void;

    /**
     * Run every composer registered for the given view.
     */
    public function fire(View $view, ClassResolver $resolver): void;
}
