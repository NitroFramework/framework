<?php

namespace Nitro\Facades;

/**
 * Redirect facade — builds redirect responses.
 *
 *   Redirect::route('orders.index');
 *   Redirect::back()->with('status', 'Saved');
 *
 * @method static \Nitro\Http\RedirectResponse to(string $path, int $status = 302)
 * @method static \Nitro\Http\RedirectResponse route(string $name, array $parameters = [], int $status = 302)
 * @method static \Nitro\Http\RedirectResponse back(int $status = 302)
 * @method static \Nitro\Http\RedirectResponse away(string $url, int $status = 302)
 * @method static \Nitro\Http\RedirectResponse intended(string $default = '/', int $status = 302)
 */
class Redirect extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'redirect';
    }
}
