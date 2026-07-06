<?php

namespace Nitro\Livewire\Synthesizers;

use UnitEnum;

/**
 * Carries a PHP enum case as component state. Backed enums round-trip by their
 * scalar value; pure enums by their case name. The enum class is stored in
 * metadata so the exact case can be rebuilt on hydrate.
 */
class EnumSynth implements Synth
{
    public function key(): string
    {
        return 'enm';
    }

    public function match(mixed $value): bool
    {
        return $value instanceof UnitEnum;
    }

    /** @param UnitEnum $value */
    public function dehydrate(mixed $value): array
    {
        // Backed enums expose ->value; pure enums only ->name.
        $payload = property_exists($value, 'value') ? $value->value : $value->name;

        return [$payload, ['class' => get_class($value)]];
    }

    public function hydrate(mixed $payload, array $meta): mixed
    {
        /** @var class-string<UnitEnum> $class */
        $class = $meta['class'];

        if (method_exists($class, 'from')) {
            return $class::from($payload);
        }

        foreach ($class::cases() as $case) {
            if ($case->name === $payload) {
                return $case;
            }
        }

        return null;
    }
}
