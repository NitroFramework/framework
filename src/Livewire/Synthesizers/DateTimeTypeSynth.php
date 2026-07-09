<?php

namespace Nitro\Livewire\Synthesizers;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Coerces an incoming wire:model value into a DateTime-typed property.
 *
 * An `<input type="date" wire:model="startsAt">` sends a date string; this parses
 * it into the property's declared DateTime class so a `public ?DateTimeImmutable
 * $startsAt` works with wire:model instead of throwing a TypeError. An empty
 * input becomes null; an unparseable value also becomes null (for validation).
 * A property typed as the interface defaults to DateTimeImmutable.
 */
class DateTimeTypeSynth implements TypeSynth
{
    public function matchType(string $type): bool
    {
        return is_a($type, DateTimeInterface::class, true);
    }

    public function hydrateFromType(string $type, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        $class = $type === DateTimeInterface::class ? DateTimeImmutable::class : $type;

        try {
            return new $class((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
