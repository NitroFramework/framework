<?php

namespace Nitro\Facades;

/**
 * Cookie facade — queues cookies onto the outgoing response.
 *
 *   Cookie::queue('theme', 'dark', 60);
 *   Cookie::forget('theme');
 *
 * @method static \Nitro\Cookie\Cookie make(string $name, string $value, int $minutes = 0)
 * @method static void queue(string $name, string $value, int $minutes = 0)
 * @method static void forget(string $name)
 * @method static bool hasQueued(string $name)
 * @method static array getQueuedCookies()
 */
class Cookie extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cookie';
    }
}
