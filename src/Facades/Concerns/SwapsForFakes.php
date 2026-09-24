<?php

namespace Nitro\Facades\Concerns;

/**
 * Puts a recorder in the container where the real service was.
 *
 * Every fake() on a facade does the same two things — build the stand-in and
 * bind it under the names the facade and the type-hints resolve — so it is
 * written once here rather than five times with one of them subtly different.
 */
trait SwapsForFakes
{
    /**
     * Bind $fake in place of the real service.
     *
     * Bound under the facade's own accessor and under the fake's parent class,
     * because a constructor type-hinting the real service has to receive the
     * fake too — otherwise half the application talks to the recorder and half
     * to the real thing.
     *
     * @template T of object
     * @param T $fake
     * @return T
     */
    protected static function swap(object $fake): object
    {
        $container = app();

        $container->instance(static::getFacadeAccessor(), $fake);

        $parent = get_parent_class($fake);

        if ($parent !== false) {
            $container->instance($parent, $fake);
        }

        return $fake;
    }
}
