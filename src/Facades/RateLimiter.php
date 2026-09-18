<?php

namespace Nitro\Facades;

/**
 * RateLimiter facade — counts attempts against a key.
 *
 *   RateLimiter::tooManyAttempts('login:'.$ip, 5);
 *   RateLimiter::hit('login:'.$ip, 60);
 *
 * @method static mixed attempt(string $key, int $maxAttempts, \Closure $callback, int $decaySeconds = 60)
 * @method static bool tooManyAttempts(string $key, int $maxAttempts)
 * @method static int hit(string $key, int $decaySeconds = 60)
 * @method static int attempts(string $key)
 * @method static int remaining(string $key, int $maxAttempts)
 * @method static int availableIn(string $key)
 * @method static void clear(string $key)
 */
class RateLimiter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'rate.limiter';
    }
}
