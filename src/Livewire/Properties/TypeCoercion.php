<?php

namespace Nitro\Livewire\Properties;

use Nitro\Livewire\Snapshot\Synthesizers\SynthManager;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Coerces an incoming wire:model value to a public property's DECLARED type.
 *
 * Everything from the browser arrives as a string, so this is where Nitro's
 * typed-property rule pays off: `public ?int $age` emptied in the UI sends "",
 * and this turns that into null rather than 0 — or a TypeError. A standalone
 * unit with no component state, so it is directly testable.
 */
class TypeCoercion
{
    /** Coerce a value for $component::$name to the property's declared type. */
    public static function coerce(object $component, string $name, mixed $value): mixed
    {
        $type = (new ReflectionProperty($component, $name))->getType();

        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        // Numeric scalars and every class type (enum, DateTime, …) go through a
        // TypeSynth — a wire:model input arrives as a string, so an emptied field
        // sends "" that must become null (not 0, not a TypeError). The other
        // builtins (bool/string/array) never hit that path, so a scalar property
        // never triggers the enum_exists()/is_a() class lookups.
        if ($typeName === 'int' || $typeName === 'float' || ! $type->isBuiltin()) {
            return static::viaSynth($type, $value);
        }

        if ($value === null) {
            return $value;
        }

        return match ($typeName) {
            'bool'   => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            'array'  => (array) $value,
            default  => $value,
        };
    }

    /** Apply the matching TypeSynth, then keep the result assignable to the type. */
    protected static function viaSynth(ReflectionNamedType $type, mixed $value): mixed
    {
        $typeName = $type->getName();
        $synth = SynthManager::typeSynthFor($typeName);

        // Unknown class type with no synth (e.g. a Model set in mount()) — leave
        // the incoming value untouched rather than guess at a coercion.
        if ($synth === null) {
            return $value;
        }

        $coerced = $synth->hydrateFromType($typeName, $value);

        // A synth may hand back a value it couldn't coerce (FloatSynth returns a
        // raw non-numeric string); a typed property can't hold that, so treat it
        // as "no value" and let validation report it instead of a TypeError.
        if ($coerced !== null && ! static::fitsType($typeName, $coerced)) {
            $coerced = null;
        }

        // A non-nullable field can't hold null (an emptied input): numerics fall
        // back to zero; other typed props should be declared nullable to accept
        // an empty value.
        if ($coerced === null && ! $type->allowsNull()) {
            return match ($typeName) {
                'int'   => 0,
                'float' => 0.0,
                default => $value,
            };
        }

        return $coerced;
    }

    /** Whether a coerced value is assignable to the declared scalar/class type. */
    public static function fitsType(string $typeName, mixed $value): bool
    {
        return match ($typeName) {
            'int'   => is_int($value),
            'float' => is_int($value) || is_float($value),
            default => $value instanceof $typeName,
        };
    }
}
