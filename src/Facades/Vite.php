<?php

namespace Nitro\Facades;

/**
 * Vite facade — emits the tags for built or hot-reloaded assets.
 *
 *   Vite::tags(['resources/css/app.css', 'resources/js/app.js']);
 *   Vite::isRunningHot();
 *
 * @method static string tags(string|array $entries)
 * @method static bool isRunningHot()
 * @method static string hotUrl()
 */
class Vite extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'vite';
    }
}
