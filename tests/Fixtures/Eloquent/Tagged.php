<?php

namespace Nitro\Tests\Fixtures\Eloquent;

/**
 * Conventional boot and initialize methods.
 */
trait Tagged
{
    public static function bootTagged(): void
    {
        BootLog::record('bootTagged', static::class);
    }

    public function initializeTagged(): void
    {
        BootLog::record('initializeTagged', static::class);
    }
}
