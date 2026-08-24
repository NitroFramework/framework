<?php

namespace Nitro\Livewire\Snapshot;

use Nitro\Livewire\Component;
use Nitro\Livewire\Runtime\ComponentRegistry;
use Nitro\Livewire\Snapshot\Synthesizers\SynthManager;
use Nitro\Livewire\Support\Slot;

/**
 * THE WIRE BOUNDARY. Everything the server knows about a component that has to
 * survive until the next request goes out through snapshot(), and comes back in
 * through fromSnapshot() — nothing else crosses.
 *
 * A snapshot is three parts: `data` (the public state, with non-scalars encoded
 * by the synthesizers), `memo` (identity and the feature entries the hooks
 * contribute — errors, listeners, #[Url] bindings, slots), and `checksum` (an
 * HMAC over both, so the client can carry the state but not change it).
 *
 * Hydration verifies the checksum FIRST, and only then rebuilds anything.
 */
class Snapshotter
{
    public function __construct(
        protected ComponentRegistry $registry,
        protected SynthManager $synths,
        protected Checksum $checksum,
    ) {}

    /**
     * Dehydrate a component into its signed transport snapshot: state, identity
     * memo, and an integrity checksum.
     *
     * @return array{data: array, memo: array, checksum: string}
     */
    public function snapshot(Component $component, array $extraMemo = []): array
    {
        $data = $this->synths->dehydrate($component->all());

        $memo = [
            'id'        => $component->getId(),
            'name'      => $component->getName(),
            'listeners' => $component->listeners(),
        ];

        // Each feature adds what it needs to survive the round trip (validation
        // errors, #[Url] bindings, …).
        $component->hooks()->dehydrate($memo);

        if (($slots = $component->slotsToArray()) !== []) {
            $memo['slots'] = $slots;
        }

        $memo = array_merge($memo, $extraMemo);

        return [
            'data'     => $data,
            'memo'     => $memo,
            'checksum' => $this->checksum->generate($data, $memo),
        ];
    }

    /**
     * Hydrate a component from a received snapshot: verify the checksum, rebuild
     * the instance for the snapshot's component, and restore its public state.
     */
    public function fromSnapshot(array $snapshot): Component
    {
        $this->checksum->verify($snapshot);

        $memo = $snapshot['memo'] ?? [];
        $name = (string) ($memo['name'] ?? '');

        $component = $this->registry->make($name);
        $component->setContext((string) ($memo['id'] ?? $this->registry->generateId()), $name);

        // Restore slots (parent-provided HTML) so the child re-renders with them.
        if (! empty($memo['slots'])) {
            $component->setSlots(array_map(
                static fn(string $html): Slot => new Slot($html),
                (array) $memo['slots']
            ));
        }

        // Give each feature back what it parked in the memo (errors, …).
        $component->hooks()->hydrate((array) $memo);

        // Expand synth tuples (models, collections, files, enums) back to objects.
        $data = $this->synths->hydrate($snapshot['data'] ?? []);

        foreach ($data as $key => $value) {
            $component->setProperty($key, $value);
        }

        return $component;
    }

    /** Decode a commit's snapshot, which may arrive as a JSON string. */
    public function decode(mixed $snapshot): array
    {
        if (is_string($snapshot)) {
            return json_decode($snapshot, true) ?: [];
        }

        return (array) $snapshot;
    }
}
