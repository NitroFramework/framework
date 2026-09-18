<?php

namespace Nitro\Facades;

/**
 * Config facade — reads and writes configuration.
 *
 *   Config::get('app.timezone', 'UTC');
 *   Config::set('mail.from', 'no-reply@example.test');
 *
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 * @method static bool has(string $key)
 * @method static array all()
 */
class Config extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'config';
    }
}
