<?php

namespace Nitro\Facades;

/**
 * Context facade — data that travels with the request or job.
 *
 *   Context::add('trace_id', $id);
 *   Context::get('trace_id');
 *
 * @method static \Nitro\Context\Repository add(string|array $key, mixed $value = null)
 * @method static \Nitro\Context\Repository addIf(string $key, mixed $value)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool has(string $key)
 * @method static bool missing(string $key)
 * @method static array all()
 * @method static array only(array $keys)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static \Nitro\Context\Repository push(string $key, mixed ...$values)
 * @method static bool stackContains(string $key, mixed $value)
 * @method static \Nitro\Context\Repository forget(string|array $keys)
 * @method static \Nitro\Context\Repository addHidden(string|array $key, mixed $value = null)
 * @method static mixed getHidden(string $key, mixed $default = null)
 * @method static bool hasHidden(string $key)
 * @method static array allHidden()
 * @method static mixed scope(array $context, \Closure $callback)
 * @method static array dehydrate()
 * @method static \Nitro\Context\Repository hydrate(?array $context)
 * @method static \Nitro\Context\Repository flush()
 */
class Context extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'context';
    }
}
