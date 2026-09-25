<?php

namespace Nitro\Blaze;

use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Foundation\Providers\ServiceProvider;
use Nitro\View\Blade;

/**
 * Wires the Blaze layer into Nitro through the framework's extension seams — a
 * Blade precompiler and a no-op @blaze directive — without touching the core
 * View layer. Enable components with Blaze::optimize()->in($dir) from a service
 * provider, or an @blaze marker at the top of a component template.
 */
class BlazeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(BlazeManager::class, static function ($container): BlazeManager {
            $config = $container->resolve(ConfigRepository::class);

            return new BlazeManager(
                (bool) $config->get('blaze.enabled', true),
                base_path('resources/views'),
                (string) $config->get('blaze.cache_path', storage_path('cache/blaze')),
                (array) $config->get('blaze.directories', [])
            );
        });

        $this->container->singleton(BlazeRuntime::class, static fn($container) => new BlazeRuntime($container->resolve(BlazeManager::class)));

        /*
         * The compiler holds no state between calls — compile() resets its one
         * flag — so a single shared instance serves every template, built on the
         * first one that needs rewriting.
         */
        $this->container->singleton(BlazeCompiler::class, static fn($container) => new BlazeCompiler(
            $container->resolve(BlazeManager::class)
        ));

        // Nothing is handed to Blaze here: Blaze::optimize() finds the manager
        // through the container on first use, so a request that never calls it
        // does not load the Blaze class at all.
    }

    public function boot(): void
    {
        $container = $this->container;

        /*
         * Rewrite eligible <x-*> tags into $__blaze->render() before core
         * compiles. Registered as a closure rather than a built instance:
         * precompilers run only when Blade actually compiles a template, which
         * with a warm compiled-view cache never happens, so constructing the
         * compiler here spent a manager and a compiler per request on a callback
         * that would not fire.
         */
        Blade::precompiler(static fn (string $template): string
            => $container->resolve(BlazeCompiler::class)->compile($template));

        // @blaze is a compile-time marker only — it emits nothing.
        Blade::directive('blaze', static fn(): string => '');
    }
}
