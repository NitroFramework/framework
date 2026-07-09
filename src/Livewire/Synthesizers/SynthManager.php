<?php

namespace Nitro\Livewire\Synthesizers;

use RuntimeException;

/**
 * Walks a component's state tree to convert non-scalar property values to and
 * from their transport form. Scalars and plain arrays pass through untouched;
 * any object is handed to the first matching Synth and encoded as a
 * [$payload, $meta] tuple (with the synth's key under $meta['s']), which the
 * matching Synth reverses on the way back in.
 */
class SynthManager
{
    /** @var Synth[] Ordered — first match wins on dehydrate. */
    protected array $synths;

    /** @param Synth[] $synths */
    public function __construct(array $synths = [])
    {
        $this->synths = $synths;
    }

    /** The default set of synthesizers wired into the Livewire layer. */
    public static function default(): self
    {
        return new self([
            new UploadedFileSynth(),
            new ModelSynth(),
            new CollectionSynth(),
            new EnumSynth(),
        ]);
    }

    /**
     * Type-based synths (Livewire matchByType parity). Unlike the value synths
     * above these coerce an incoming wire:model value into a property's DECLARED
     * scalar type — chiefly fixing empty-string → null for numeric fields. Used
     * by Component::coerce(); memoized as they're stateless.
     *
     * @var TypeSynth[]|null
     */
    private static ?array $typeSynths = null;

    /**
     * @return TypeSynth[] Ordered so the cheap scalar checks (Float/Int, plain
     *   string compares) run before the class-based ones (Enum/DateTime, which
     *   do enum_exists()/is_a()) — so a scalar property never triggers a class
     *   lookup. First match wins.
     */
    public static function typeSynths(): array
    {
        return self::$typeSynths ??= [
            new FloatSynth(),
            new IntSynth(),
            new EnumTypeSynth(),
            new DateTimeTypeSynth(),
        ];
    }

    /** The type synth that coerces the given declared type name, or null. */
    public static function typeSynthFor(string $type): ?TypeSynth
    {
        foreach (self::typeSynths() as $synth) {
            if ($synth->matchType($type)) {
                return $synth;
            }
        }

        return null;
    }

    /** Register an additional synthesizer (takes precedence over later ones). */
    public function register(Synth $synth): void
    {
        array_unshift($this->synths, $synth);
    }

    /**
     * Encode a value (typically the component's full state array) into its
     * JSON-safe transport form, recursing through arrays.
     */
    public function dehydrate(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn($v) => $this->dehydrate($v), $value);
        }

        if (! is_object($value)) {
            return $value;
        }

        foreach ($this->synths as $synth) {
            if ($synth->match($value)) {
                [$payload, $meta] = $synth->dehydrate($value);
                $meta['s'] = $synth->key();

                return [$this->dehydrate($payload), $meta];
            }
        }

        throw new RuntimeException(
            'Livewire cannot serialize property value of type [' . get_class($value) . ']. '
            . 'Register a synthesizer for it, or keep the value scalar/array.'
        );
    }

    /**
     * Decode a transport value back into live objects, recursing through arrays
     * and expanding any [$payload, $meta] synth tuples it finds.
     */
    public function hydrate(mixed $value): mixed
    {
        if ($this->isSynthTuple($value)) {
            $meta = $value[1];
            $synth = $this->synthForKey($meta['s']);

            return $synth->hydrate($this->hydrate($value[0]), $meta);
        }

        if (is_array($value)) {
            return array_map(fn($v) => $this->hydrate($v), $value);
        }

        return $value;
    }

    /** Whether a value is a dehydrated [$payload, $meta] synth tuple. */
    protected function isSynthTuple(mixed $value): bool
    {
        return is_array($value)
            && count($value) === 2
            && array_key_exists(0, $value)
            && array_key_exists(1, $value)
            && is_array($value[1])
            && isset($value[1]['s']);
    }

    /** Resolve a synth by its stored key, or fail loudly on an unknown tag. */
    protected function synthForKey(string $key): Synth
    {
        foreach ($this->synths as $synth) {
            if ($synth->key() === $key) {
                return $synth;
            }
        }

        throw new RuntimeException("No Livewire synthesizer registered for key [{$key}].");
    }
}
