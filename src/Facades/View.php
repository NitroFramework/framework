<?php

namespace Nitro\Facades;

/**
 * View facade — renders views and shares data with all of them.
 *
 *   View::make('orders.show', ['order' => $order]);
 *   View::share('appName', config('app.name'));
 *
 * @method static string make(string $view, array $data = [])
 * @method static void share(string|array $key, mixed $value = null)
 * @method static bool exists(string $view)
 * @method static void composer(string|array $views, callable $callback)
 */
class View extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'view.factory';
    }
}
