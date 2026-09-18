<?php

namespace Nitro\Facades;

/**
 * Schedule facade — defines scheduled work.
 *
 *   Schedule::command('queue:prune')->daily();
 *
 * @method static \Nitro\Scheduling\Event command(string $command, array $arguments = [])
 * @method static \Nitro\Scheduling\Event call(callable $callback)
 * @method static array events()
 * @method static void run()
 */
class Schedule extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'schedule';
    }
}
