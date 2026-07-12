<?php

namespace Nitro\Fusion\State;

use BackedEnum;
use JsonSerializable;
use ReflectionNamedType;

/**
 * Fusion-native (de)serialization of a #[Client] component's reactive props at
 * the client boundary. Fusion state is plain, JSON-round-trippable data — it
 * becomes reactive JS state in the browser — so this is deliberately small and
 * has NO dependency on the Livewire layer.
 *
 * Supported reactive prop types: scalars, arrays (recursively), and backed enums
 * (<-> their `->value`, coerced back via the prop's declared type).
 * JsonSerializable / toArray() objects are dehydrated best-effort for display.
 * A live model is not client-reactive state by design, so it isn't a concern
 * here — that heavier round-trip is Livewire's job, and Fusion no longer borrows it.
 */
final class StateSerializer
{
    /** A prop value → its JSON-safe transport form. */
    public static function dehydrate(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_map([self::class, 'dehydrate'], $value);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return $value; // best effort — json_encode handles it downstream
    }

    /**
     * A client-sent value → the prop's declared type. Scalars/arrays assign
     * directly (PHP coerces on a typed assignment); a backed-enum prop is
     * rebuilt from its backing value.
     */
    public static function hydrate(mixed $value, ?ReflectionNamedType $type = null): mixed
    {
        if ($value === null || $type === null || $type->isBuiltin()) {
            return $value;
        }

        $name = $type->getName();

        if (is_a($name, BackedEnum::class, true)) {
            return $value instanceof BackedEnum ? $value : $name::from($value);
        }

        return $value;
    }
}
