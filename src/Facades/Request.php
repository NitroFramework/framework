<?php

namespace Nitro\Facades;

/**
 * Request facade — the request currently being handled.
 *
 *   Request::input('email');
 *   Request::isMethod('post');
 *
 * @method static mixed input(string $key, mixed $default = null)
 * @method static array all()
 * @method static bool has(string|array $key)
 * @method static bool filled(string|array $key)
 * @method static string method()
 * @method static string path()
 * @method static string url()
 * @method static string fullUrl()
 * @method static string ip()
 * @method static mixed header(string $key, mixed $default = null)
 * @method static bool expectsJson()
 * @method static \Nitro\Http\UploadedFile|null file(string $key)
 */
class Request extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'request';
    }
}
