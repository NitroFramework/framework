<?php

namespace Nitro\Inertia\Contracts;

use Nitro\Inertia\PropertyContext;

/**
 * An object that decides what it serializes to when used as a prop.
 *
 * The singular counterpart to {@see ProvidesInertiaProperties}: that one
 * contributes a set of props under its own keys, this one *is* the value of
 * the key it was given.
 */
interface ProvidesInertiaProperty
{
    public function toInertiaProperty(PropertyContext $prop): mixed;
}
