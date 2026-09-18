<?php

namespace Nitro\View\Support;

use Nitro\Container\Container;
use Nitro\View\Contracts\ComposerInterface;
use Nitro\View\Contracts\ViewComposerResolver;
use Nitro\View\View;

/**
 * Holds the registered view composers and runs the ones a view matches.
 *
 * A composer named by class is resolved when it fires, not when registered.
 */
class ComposerResolver implements ViewComposerResolver
{
    /**
     * Registered composers, keyed by the pattern they were registered against.
     *
     * @var array<string, array<int, callable|string>>
     */
    private array $composers = [];

    /**
     * Register a composer against one or more view names or patterns.
     *
     * @param string|array<int, string> $templates
     * @param callable|string           $composer A callable, or the name of a
     *                                            class resolved when it fires.
     */
    public function register(string|array $templates, callable|string $composer): void
    {
        foreach ((array) $templates as $template) {
            $this->composers[$template][] = $composer;
        }
    }

    /**
     * Run every composer whose pattern matches this view, in registration order.
     */
    public function fire(View $view, Container $container): void
    {
        foreach ($this->composers as $pattern => $composers) {
            if (! $this->matches($pattern, $view->name())) {
                continue;
            }

            foreach ($composers as $composer) {
                $this->resolve($composer, $container)->compose($view);
            }
        }
    }

    /**
     * Whether a registered pattern covers a view name.
     *
     * Three forms are understood: `*` for every view, an exact name, and
     * `prefix.*` for every view beneath a prefix.
     */
    private function matches(string $pattern, string $template): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if ($pattern === $template) {
            return true;
        }

        if (str_ends_with($pattern, '.*')) {
            return str_starts_with($template, rtrim($pattern, '.*') . '.');
        }

        return false;
    }

    /**
     * Turn a registered composer into something with a compose() method.
     *
     * A class name is resolved from the container so its dependencies are
     * injected; a callable is wrapped so both forms are invoked identically.
     */
    private function resolve(callable|string $composer, Container $container): ComposerInterface
    {
        if (is_string($composer)) {
            return $container->createOrResolve($composer);
        }

        return new class ($composer) implements ComposerInterface {
            /** @param callable $callable */
            public function __construct(private $callable)
            {
            }

            /** Run every composer registered against a view before it renders. */
            public function compose(View $view): void
            {
                ($this->callable)($view);
            }
        };
    }
}
