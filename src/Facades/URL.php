<?php

namespace Nitro\Facades;

/**
 * URL facade — builds URLs from named routes and paths.
 *
 *   URL::route('orders.show', ['order' => 1]);
 *   URL::to('/pricing');
 *
 * @method static string route(string $name, array $parameters = [], bool $absolute = true)
 * @method static string to(string $path, array $parameters = [], ?bool $secure = null)
 * @method static string current()
 * @method static string full()
 * @method static bool has(string $name)
 */
class URL extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'router';
    }
}
