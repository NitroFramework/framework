<?php

namespace Nitro\Livewire\Synthesizers;

use BackedEnum;
use UnitEnum;

/**
 * Coerces an incoming wire:model value into an enum-typed property.
 *
 * A `<select wire:model="status">` sends the option's string value; this turns
 * it into the matching enum case so a `public ?Status $status` property (backed
 * or pure) works with wire:model instead of throwing a TypeError. An empty
 * selection becomes null.
 *
 * The counterpart to {@see EnumSynth}, which round-trips an enum through the
 * snapshot; this one handles the fresh value arriving from the browser.
 */
class EnumTypeSynth implements TypeSynth
{
    public function matchType(string $type): bool
    {
        return function_exists('enum_exists') && enum_exists($type);
    }

    public function hydrateFromType(string $type, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if ($value instanceof UnitEnum) {
            return $value;
        }

        // Backed enum: coerce from its scalar value (null when it isn't a case).
        if (is_subclass_of($type, BackedEnum::class)) {
            return $type::tryFrom($value);
        }

        // Pure enum: match by case name.
        foreach ($type::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        return null;
    }
}
