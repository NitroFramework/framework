<?php

namespace Nitro\Components;

use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Component;
use Illuminate\View\DynamicComponent;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\FileEngine;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Nitro\Foundation\Application;

/**
 * ViewServiceProvider, wired directly.
 */
final class View
{
    public static function factory(Application $app): Factory
    {
        $factory = new Factory($app->make('view.engine.resolver'), $app->make('view.finder'), $app->make('events'));
        $factory->setContainer($app);
        $factory->share('app', $app);

        $app->terminating(static function () {
            Component::flushCache();
            Component::forgetFactory();
        });

        return $factory;
    }

    public static function finder(Application $app): FileViewFinder
    {
        return new FileViewFinder($app->make('files'), $app->make('config')->get('view.paths', [$app->resourcePath('views')]));
    }

    public static function blade(Application $app): BladeCompiler
    {
        $config = $app->make('config');

        $blade = new BladeCompiler(
            $app->make('files'),
            $config->get('view.compiled', $app->storagePath('framework/views')),
            $config->get('view.relative_hash', false) ? $app->basePath() : '',
            $config->get('view.cache', true),
            $config->get('view.compiled_extension', 'php'),
            /** After `artisan view:warm` in production, skip per-render source mtime checks. */
            $config->get('view.check_cache_timestamps', true) && ! $app->viewsAreWarm(),
        );

        $blade->component('dynamic-component', DynamicComponent::class);

        return $blade;
    }

    public static function engines(Application $app): EngineResolver
    {
        $resolver = new EngineResolver;

        $resolver->register('file', static fn () => new FileEngine($app->make('files')));
        $resolver->register('php', static fn () => new PhpEngine($app->make('files')));
        $resolver->register('blade', static function () use ($app) {
            $compiler = new CompilerEngine($app->make('blade.compiler'), $app->make('files'));

            $app->terminating(static fn () => $compiler->forgetCompiledOrNotExpired());

            return $compiler;
        });

        return $resolver;
    }
}
