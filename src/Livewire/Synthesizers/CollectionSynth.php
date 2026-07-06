<?php

namespace Nitro\Livewire\Synthesizers;

use Nitro\Support\Collection;

/**
 * Carries a Support\Collection as component state. The items are dehydrated
 * recursively (so a collection of models round-trips through the ModelSynth)
 * and re-wrapped in a Collection on the way back in.
 */
class CollectionSynth implements Synth
{
    public function key(): string
    {
        return 'clc';
    }

    public function match(mixed $value): bool
    {
        return $value instanceof Collection;
    }

    /** @param Collection $value */
    public function dehydrate(mixed $value): array
    {
        return [$value->all(), []];
    }

    public function hydrate(mixed $payload, array $meta): mixed
    {
        return new Collection(is_array($payload) ? $payload : []);
    }
}
