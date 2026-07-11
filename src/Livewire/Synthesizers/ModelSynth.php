<?php

namespace Nitro\Livewire\Synthesizers;

use Nitro\Database\Model\Model;
use Nitro\Livewire\SecurityPolicy;

/**
 * Carries a Nitro model as component state. A persisted model is stored by
 * class + primary key and re-fetched on hydrate, so actions receive a live,
 * saveable record; an unsaved model is stored by its attributes and rebuilt in
 * place. The dehydrated payload is the model's array form, which the view and
 * client can read (e.g. wire:model="student.name") without a database hit.
 */
class ModelSynth implements Synth
{
    public function key(): string
    {
        return 'mdl';
    }

    public function match(mixed $value): bool
    {
        return $value instanceof Model;
    }

    /** @param Model $value */
    public function dehydrate(mixed $value): array
    {
        $key = $value->getKey();

        return [
            $value->toArray(),
            ['class' => get_class($value), 'key' => $key],
        ];
    }

    public function hydrate(mixed $payload, array $meta): mixed
    {
        /** @var class-string<Model> $class */
        $class = $meta['class'] ?? '';
        $key = $meta['key'] ?? null;

        // Defense-in-depth: the snapshot is checksum-verified before we get here,
        // but constrain what this synth may instantiate anyway — a checksum
        // bypass (e.g. a leaked APP_KEY) must not be able to turn `new $class` /
        // `$class::find()` into a gadget sink. The class must be a real Model and
        // must not be on the security denylist.
        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            throw new \RuntimeException(
                'ModelSynth refused to hydrate a non-Model class from a snapshot: '
                . (is_string($class) ? $class : get_debug_type($class))
            );
        }
        SecurityPolicy::validateClass($class);

        // Persisted record: re-fetch so callers get a live, saveable model.
        if ($key !== null && $key !== '') {
            $found = $class::find($key);

            if ($found !== null) {
                return $found;
            }
        }

        // Unsaved (or since-deleted): rebuild from the carried attributes.
        return new $class(is_array($payload) ? $payload : []);
    }
}
