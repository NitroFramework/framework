<?php

namespace Nitro\Livewire\Synthesizers;

/**
 * Coerces incoming wire:model values for `float`-typed properties.
 *
 * A wire:model input arrives as a string, so an emptied numeric field sends "".
 * A plain (float) cast would turn that into 0.0 — silently writing a real zero
 * where the user meant "no value". This synth turns "" (and null) into null so a
 * nullable `?float` property, and a nullable DB column behind it, stays empty.
 *
 * Ported from Livewire 4's FloatSynth (matchByType('float')).
 */
class FloatSynth implements TypeSynth
{
    public function matchType(string $type): bool
    {
        return $type === 'float';
    }

    public function hydrateFromType(string $type, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        // Non-numeric input can't be a float — leave it for validation to reject.
        // (Component::coerce() normalizes this away from a typed float property.)
        return $value;
    }
}
