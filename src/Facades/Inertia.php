<?php

namespace Nitro\Facades;

/**
 * Inertia facade — renders a client-side page from a controller.
 *
 *   return Inertia::render('Students/Index', ['students' => $page]);
 *
 * @method static void setRootView(string $name)
 * @method static void share(string|array $key, mixed $value = null)
 * @method static mixed getShared(?string $key = null, mixed $default = null)
 * @method static void flushShared()
 * @method static void version(\Closure|string|null $version)
 * @method static string getVersion()
 * @method static void clearHistory()
 * @method static void preserveFragment()
 * @method static void encryptHistory(bool $encrypt = true)
 * @method static \Nitro\Inertia\Props\OptionalProp optional(callable $callback)
 * @method static \Nitro\Inertia\Props\DeferProp defer(callable $callback, string $group = 'default')
 * @method static \Nitro\Inertia\Props\MergeProp merge(mixed $value)
 * @method static \Nitro\Inertia\Props\MergeProp deepMerge(mixed $value)
 * @method static \Nitro\Inertia\Props\AlwaysProp always(mixed $value)
 * @method static \Nitro\Inertia\Props\OnceProp once(callable $callback)
 * @method static void shareOnce(string $key, callable $callback)
 * @method static \Nitro\Inertia\Props\ScrollProp scroll(mixed $value, string $wrapper = 'data', \Nitro\Inertia\Contracts\ProvidesScrollMetadata|callable|null $metadata = null)
 * @method static \Nitro\Inertia\Response render(string $component, array $props = [])
 * @method static \Nitro\Http\Response location(string|\Nitro\Http\RedirectResponse $url)
 * @method static \Nitro\Inertia\ResponseFactory flash(string|array $key, mixed $value = null)
 * @method static array getFlashed()
 * @method static array pullFlashed()
 *
 * @see \Nitro\Inertia\ResponseFactory
 */
class Inertia extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'inertia';
    }
}
