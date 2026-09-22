<?php

namespace Nitro\Inertia;

use Nitro\Facades\Blade;
use Nitro\Foundation\Providers\ServiceProvider;

/**
 * Registers the Inertia layer: the response factory behind `Inertia::` and
 * `inertia()`, and the Blade directives a root template uses.
 *
 * The factory is a singleton because shared props and the asset version are
 * stated once per request and read by every page built during it.
 */
class InertiaServiceProvider extends ServiceProvider
{
    /**
     * Deferred: an application that renders no Inertia pages never loads this.
     *
     * The ordering still holds for the ones that do — the middleware resolves
     * the factory at the start of a request, and a controller resolves it to
     * render, both of which happen before the root template is compiled and
     * the directives are needed.
     */
    protected bool $defer = true;

    /** @return array<int, string> */
    public function provides(): array
    {
        return ['inertia', ResponseFactory::class];
    }

    public function register(): void
    {
        $this->container->singleton('inertia', static fn (): ResponseFactory => new ResponseFactory());

        $this->container->alias('inertia', ResponseFactory::class);
    }

    public function boot(): void
    {
        Blade::directive('inertia', static fn (string $expression = ''): string
            => Directive::compile($expression));

        Blade::directive('inertiaHead', static fn (string $expression = ''): string
            => Directive::compileHead($expression));
    }
}
