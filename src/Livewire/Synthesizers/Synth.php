<?php

namespace Nitro\Livewire\Synthesizers;

/**
 * A synthesizer teaches the Livewire layer how to carry a non-scalar property
 * value (an Eloquent-style model, a collection, an enum, an uploaded file)
 * across the request boundary. On dehydrate it splits the value into a
 * JSON-safe payload plus metadata; on hydrate it reconstructs the original
 * object from that pair. Registered with and driven by the SynthManager.
 */
interface Synth
{
    /** The short, stable key stamped into the snapshot so hydrate can find this synth again. */
    public function key(): string;

    /** Whether this synth is responsible for dehydrating the given value. */
    public function match(mixed $value): bool;

    /**
     * Split a value into its transport form.
     *
     * @return array{0: mixed, 1: array}  [$payload, $meta] — $payload must be JSON-safe.
     */
    public function dehydrate(mixed $value): array;

    /** Rebuild the original value from a dehydrated payload and its metadata. */
    public function hydrate(mixed $payload, array $meta): mixed;
}
