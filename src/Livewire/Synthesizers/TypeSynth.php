<?php

namespace Nitro\Livewire\Synthesizers;

/**
 * A type-based synthesizer: unlike {@see Synth} (which matches an object VALUE
 * on dehydrate), a TypeSynth matches on a property's DECLARED scalar type and
 * coerces an incoming wire:model value into it.
 *
 * This mirrors Livewire's matchByType()/hydrateFromType() path. It exists mainly
 * to fix the empty-string edge: a wire:model input always arrives as a string,
 * so an emptied numeric field sends "" — which a naive (float) cast turns into
 * 0.0. A TypeSynth turns it into null instead (see FloatSynth / IntSynth).
 */
interface TypeSynth
{
    /** Whether this synth coerces values for the given declared type name (e.g. 'float'). */
    public function matchType(string $type): bool;

    /** Coerce an incoming (string) value into the declared type. */
    public function hydrateFromType(string $type, mixed $value): mixed;
}
