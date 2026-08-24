<?php

namespace Nitro\Blaze;

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
        $this->container->singleton(BlazeManager::class, static function (): BlazeManager {
            return new BlazeManager(
                (bool) config('blaze.enabled', true),
                base_path('resources/views'),
                (string) config('blaze.cache_path', storage_path('cache/blaze')),
                (array) config('blaze.directories', [])
            );
        });

        $this->container->singleton(BlazeRuntime::class, static fn($container) => new BlazeRuntime($container->createOrResolve(BlazeManager::class)));

        // Available to service providers immediately (before any boot()).
        Blaze::setManager($this->container->createOrResolve(BlazeManager::class));
    }

    public function boot(): void
    {
        $manager = $this->container->createOrResolve(BlazeManager::class);

        // Rewrite eligible <x-*> tags into $__blaze->render() before core compiles.
        Blade::precompiler([new BlazeCompiler($manager), 'compile']);

        // @blaze is a compile-time marker only — it emits nothing.
        Blade::directive('blaze', static fn(): string => '');
    }
}
