<?php

namespace Nitro\Tests\Fixtures\Package;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Written exactly like a real Laravel package provider: nothing Nitro-specific.
 */
class AcmeServiceProvider extends ServiceProvider
{
    public static int $registered = 0;

    public static int $booted = 0;

    public function register(): void
    {
        static::$registered++;

        $this->mergeConfigFrom(__DIR__.'/config/acme.php', 'acme');

        $this->app->singleton('acme.greeter', fn ($app) => new AcmeGreeter($app['config']['acme.greeting']));
        $this->app->alias('acme.greeter', AcmeGreeter::class);
    }

    public function boot(Router $router): void
    {
        static::$booted++;

        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadViewsFrom(__DIR__.'/views', 'acme');

        $router->aliasMiddleware('acme', AcmeMiddleware::class);
        $router->pushMiddlewareToGroup('web', AcmeWebMiddleware::class);

        $this->app->make(Kernel::class)->pushMiddleware(AcmeGlobalMiddleware::class);

        Event::listen(AcmeEvent::class, fn (AcmeEvent $e) => $e->handled = true);

        $this->publishes([__DIR__.'/config/acme.php' => config_path('acme.php')], 'acme-config');

        if ($this->app->runningInConsole()) {
            $this->commands([AcmeCommand::class]);
        }
    }
}
