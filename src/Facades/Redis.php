<?php

namespace Nitro\Facades;

/**
 * Redis facade — access Redis connections.
 *
 *   Redis::set('k', 'v');   Redis::get('k');
 *   Redis::connection('cache')->hset('h', 'f', 'v');
 *
 * @method static \Nitro\Redis\Connections\PhpRedisConnection connection(?string $name = null)
 * @method static mixed set(string $key, mixed $value)
 * @method static mixed get(string $key)
 * @method static mixed del(string|array ...$keys)
 * @method static mixed expire(string $key, int $seconds)
 * @method static mixed command(string $method, array $parameters = [])
 */
class Redis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'redis';
    }
}
