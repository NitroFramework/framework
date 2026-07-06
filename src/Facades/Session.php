<?php

namespace Nitro\Facades;

/**
 * Session facade — Laravel's `Session::`.
 *
 *   Session::get('key');  Session::put('key', $v);  Session::flash('status', $v);
 *
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void  put(string|array $key, mixed $value = null)
 * @method static bool  has(string $key)
 * @method static void  forget(array|string $keys)
 * @method static void  flash(string $key, mixed $value = true)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static array all()
 */
class Session extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'session';
    }
}
