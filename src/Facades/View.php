<?php

namespace Nitro\Facades;

use Nitro\View\View as BaseView;
use Nitro\View\Factory;

/**
 * View facade — chooses views, shares data with all of them, and registers
 * the composers and creators that prepare them.
 *
 *   View::make('orders.show', ['order' => $order]);
 *   View::share('appName', config('app.name'));
 *   View::composer('orders.*', OrderComposer::class);
 *   View::creator('profile', fn ($view) => $view->with('user', auth()->user()));
 *
 * @method static BaseView make(string $template, array $data = [], array $mergeData = [])
 * @method static BaseView first(array $views, array $data = [], array $mergeData = [])
 * @method static BaseView file(string $path, array $data = [], array $mergeData = [])
 * @method static bool exists(string $view)
 * @method static string renderWhen(bool $condition, string $view, array $data = [], array $mergeData = [])
 * @method static string renderUnless(bool $condition, string $view, array $data = [], array $mergeData = [])
 * @method static string renderPartial(string $view, array $data = [])
 * @method static string renderFragment(string $view, string $fragment, array $data = [])
 * @method static string renderFragments(string $view, array $fragments, array $data = [])
 * @method static mixed share(array|string $key, mixed $value = null)
 * @method static mixed shared(string $key, mixed $default = null)
 * @method static array getShared()
 * @method static array composer(array|string $views, callable|string $callback)
 * @method static array composers(array $composers)
 * @method static array creator(array|string $views, callable|string $callback)
 * @method static void callComposer(BaseView $view)
 * @method static void callCreator(BaseView $view)
 * @method static void addNamespace(string $namespace, string|array $paths)
 * @method static void prependNamespace(string $namespace, string|array $paths)
 * @method static void replaceNamespace(string $namespace, string|array $paths)
 * @method static void addLocation(string $path)
 * @method static void prependLocation(string $path)
 * @method static array getLocations()
 * @method static bool hasSection(string $name)
 * @method static string getSection(string $name, string $default = '')
 *
 * @see Factory
 */
class View extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'view.factory';
    }
}
