<?php

namespace Nitro\Blaze;

use RuntimeException;

/**
 * Entry point for enabling Blaze from a service provider, mirroring
 * livewire/blaze's API:
 *
 *     Blaze::optimize()->in(resource_path('views/components'));
 */
class Blaze
{
    protected static ?BlazeManager $manager = null;

    /** Bound by BlazeServiceProvider once the manager exists. */
    public static function setManager(BlazeManager $manager): void
    {
        self::$manager = $manager;
    }

    /** Begin registering directories of components to optimize. */
    public static function optimize(): OptimizeBuilder
    {
        if (self::$manager === null) {
            throw new RuntimeException('Blaze is not booted — register Nitro\\Blaze\\BlazeServiceProvider.');
        }

        return new OptimizeBuilder(self::$manager);
    }
}
