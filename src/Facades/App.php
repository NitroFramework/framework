<?php

namespace Nitro\Facades;

/**
 * Application facade — the application instance itself.
 *
 *   App::environment();
 *   App::isDebug();
 *
 * @method static string environment()
 * @method static bool isDebug()
 * @method static string basePath(string $path = '')
 * @method static void register(string|\Nitro\Foundation\Providers\ServiceProvider $provider)
 * @method static \Nitro\Container\Contracts\ContainerInterface getContainer()
 */
class App extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'app';
    }
}
