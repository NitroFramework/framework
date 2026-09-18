<?php

namespace Nitro\Facades;

/**
 * Artisan facade — runs console commands from application code.
 *
 *   Artisan::call('queue:work', ['--once']);
 *
 * @method static int call(string $command, array $arguments = [])
 * @method static array all()
 * @method static void register(string $command)
 */
class Artisan extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'artisan';
    }
}
