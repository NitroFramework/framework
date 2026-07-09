<?php

namespace Nitro\Livewire\Synthesizers;

/**
 * Coerces incoming wire:model values for `int`-typed properties.
 *
 * Same empty-string problem as {@see FloatSynth}: an emptied number field sends
 * "", and a plain (int) cast makes that 0. This turns "" (and null) into null so
 * a nullable `?int` property stays empty rather than silently becoming zero.
 *
 * Ported from Livewire 4's IntSynth (matchByType('int')).
 */
class IntSynth implements TypeSynth
{
    public function matchType(string $type): bool
    {
        return $type === 'int';
    }

    public function hydrateFromType(string $type, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        // Only whole-number input becomes an int; "3.5"/"abc" are left for
        // validation. Component::coerce() normalizes non-ints away from the
        // typed property so they can't cause a TypeError on assignment.
        if (is_numeric($value) && (int) $value == $value) {
            return (int) $value;
        }

        return $value;
    }
}
