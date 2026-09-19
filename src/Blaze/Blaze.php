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

    /**
     * Builds the manager on first use.
     *
     * Registered instead of an instance so that a request which never calls
     * optimize() and never compiles a template does not construct the manager
     * at all — it reads config and paths, and BlazeServiceProvider::register()
     * runs on every request whether or not Blaze is used.
     *
     * @var (\Closure(): BlazeManager)|null
     */
    protected static ?\Closure $resolver = null;

    /** Bound by BlazeServiceProvider once the manager exists. */
    public static function setManager(BlazeManager $manager): void
    {
        self::$manager = $manager;
    }

    /** Defer the manager's construction to the first call that needs it. */
    public static function resolveManagerUsing(\Closure $resolver): void
    {
        self::$resolver = $resolver;
        self::$manager = null;
    }

    /** Begin registering directories of components to optimize. */
    public static function optimize(): OptimizeBuilder
    {
        return new OptimizeBuilder(self::manager());
    }

    /**
     * The manager, built from the registered resolver on first use.
     *
     * @throws RuntimeException When neither an instance nor a resolver is set.
     */
    protected static function manager(): BlazeManager
    {
        if (self::$manager !== null) {
            return self::$manager;
        }

        if (self::$resolver === null) {
            throw new RuntimeException('Blaze is not booted — register Nitro\\Blaze\\BlazeServiceProvider.');
        }

        return self::$manager = (self::$resolver)();
    }
}
