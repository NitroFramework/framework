<?php

namespace Nitro\Fusion\Concerns;

use Nitro\Livewire\Synthesizers\SynthManager;
use ReflectionObject;
use ReflectionProperty;

/**
 * Capability trait for a `#[Client]` component. It carries the server-side plumbing
 * a transpiled component needs:
 *
 *  - {@see fusionState()} serializes the public props into the initial state the
 *    SSR page embeds for the client to boot from (hydration).
 *  - {@see fusionFill()} rebuilds those props from a client-sent state — used on
 *    the server when handling a `#[Server]` RPC call, so the real method runs
 *    against the same state the browser had.
 *
 * Non-scalar props (models, enums, collections) round-trip through Livewire's
 * {@see SynthManager} — Fusion reuses Livewire's serialization rather than
 * inventing its own. Only PUBLIC props cross the boundary; protected/private
 * state is server-only and is never sent to, or writable from, the client.
 */
trait Transpilable
{
    /** Serialize public props → the transport state embedded for client hydration. */
    public function fusionState(): array
    {
        $synths = SynthManager::default();
        $state = [];

        foreach ($this->fusionPublicProps() as $name) {
            $state[$name] = $synths->dehydrate($this->{$name} ?? null);
        }

        return $state;
    }

    /** Rebuild public props from a client-sent state (server-side, for #[Server] calls). */
    public function fusionFill(array $state): static
    {
        $synths = SynthManager::default();
        $public = $this->fusionPublicProps();

        foreach ($state as $name => $value) {
            // Only public props are client-writable — protected/private stay server-only.
            if (in_array($name, $public, true)) {
                $this->{$name} = $synths->hydrate($value);
            }
        }

        return $this;
    }

    /** Public, non-static property names — the component's reactive state. @return string[] */
    protected function fusionPublicProps(): array
    {
        $names = [];
        foreach ((new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if (! $prop->isStatic()) {
                $names[] = $prop->getName();
            }
        }
        return $names;
    }
}
