<?php

namespace Nitro\Facades;

/**
 * Log facade — writes to the application log.
 *
 *   Log::info('Order placed', ['id' => $order->id]);
 *   Log::error($exception->getMessage());
 *
 * @method static void emergency(string $message, array $context = [])
 * @method static void alert(string $message, array $context = [])
 * @method static void critical(string $message, array $context = [])
 * @method static void error(string $message, array $context = [])
 * @method static void warning(string $message, array $context = [])
 * @method static void notice(string $message, array $context = [])
 * @method static void info(string $message, array $context = [])
 * @method static void debug(string $message, array $context = [])
 * @method static void log(string $level, string $message, array $context = [])
 */
class Log extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'log';
    }
}
