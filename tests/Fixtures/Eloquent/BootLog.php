<?php

namespace Nitro\Tests\Fixtures\Eloquent;

/**
 * What the fixture models' boot methods, initializers and boot events did, in order.
 */
final class BootLog
{
    /** @var list<string> */
    public static array $calls = [];

    public static function record(string $what, string $class): void
    {
        self::$calls[] = $what.' '.$class;
    }
}
