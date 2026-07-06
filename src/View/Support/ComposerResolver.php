<?php

namespace Nitro\View\Support;

use Nitro\Container\Container;
use Nitro\View\Engine\View;
use Nitro\View\Contracts\ViewComposerResolver;
use Nitro\View\Contracts\ComposerInterface;

/**
 * Resolves and runs the view composers registered for a view.
 */
class ComposerResolver implements ViewComposerResolver
{
    private array $composers = [];

    public function register(string|array $templates, callable|string $composer): void
    {
        foreach ((array) $templates as $template) {
            $this->composers[$template][] = $composer;
        }
    }

    public function fire(View $view, Container $container): void
    {
        foreach ($this->composers as $pattern => $composers) {
            if ($this->matches($pattern, $view->template())) {
                foreach ($composers as $composer) {
                    $this->resolve($composer, $container)->compose($view);
                }
            }
        }
    }

    private function matches(string $pattern, string $template): bool
    {
        if ($pattern === '*') return true;

        if ($pattern === $template) return true;

        if (str_ends_with($pattern, '.*')) {
            $prefix = rtrim($pattern, '.*');
            return str_starts_with($template, $prefix . '.');
        }

        return false;
    }

    private function resolve(callable|string $composer, Container $container): ComposerInterface
    {
        if (is_string($composer)) {
            return $container->make($composer);
        }

        return new class($composer) implements ComposerInterface {
            public function __construct(private $callable) {}
            public function compose(View $view): void
            {
                ($this->callable)($view);
            }
        };
    }
}
