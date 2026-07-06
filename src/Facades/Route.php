<?php

namespace Nitro\Facades;

/**
 * Static facade over the router — Laravel's `Route::` surface.
 *
 *   Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
 *   Route::post('/login', [LoginController::class, 'store']);
 *   Route::group(['middleware' => 'auth'], function () { ... });
 *
 * Every call forwards to the singleton 'router', so route groups, named routes,
 * and macros (e.g. ->htmx()) behave exactly as with the injected $router. Use
 * whichever you prefer — both drive the same router instance.
 *
 * The @method tags below tell IDEs/static analysers what __callStatic forwards
 * to (the router's real methods), silencing "method not defined" warnings — the
 * same approach Laravel's facades use.
 *
 * @method static \Nitro\Routing\Router get(string $path, mixed $handler)
 * @method static \Nitro\Routing\Router post(string $path, mixed $handler)
 * @method static \Nitro\Routing\Router put(string $path, mixed $handler)
 * @method static \Nitro\Routing\Router patch(string $path, mixed $handler)
 * @method static \Nitro\Routing\Router delete(string $path, mixed $handler)
 * @method static \Nitro\Routing\Router any(string $path, mixed $handler)
 * @method static \Nitro\Routing\Router match(array $methods, string $path, mixed $handler)
 * @method static \Nitro\Routing\Router view(string $path, string $viewName, array $data = [])
 * @method static \Nitro\Routing\Router resource(string $name, string $controller, array $options = [])
 * @method static \Nitro\Routing\Router group(array $attributes, \Closure $callback)
 * @method static \Nitro\Routing\Router name(string $name)
 * @method static \Nitro\Routing\Router middleware(string|array $middleware)
 * @method static \Nitro\Routing\Router prefix(string $prefix)
 * @method static \Nitro\Routing\Router htmx(string $path, string $component, string $action = 'index')
 * @method static string route(string $name, array $parameters = [])
 */
class Route extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'router';
    }
}
