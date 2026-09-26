<?php

namespace Nitro\Tests\Fixtures\Eloquent;

/**
 * A private boot method: Laravel invokes it through reflection.
 */
trait Secret
{
    private static function bootSecret(): void
    {
        BootLog::record('bootSecret', static::class);
    }
}
