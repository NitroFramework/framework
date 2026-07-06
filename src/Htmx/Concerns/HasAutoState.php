<?php

namespace Nitro\Htmx\Concerns;

use Nitro\Htmx\State\StateStore;

/**
 * Auto-load/persist public properties through the configured state store
 * so a component can be written as if its public properties are its state
 * — Livewire-style, minus the snapshot-on-every-request overhead.
 *
 *   class Counter extends HtmxComponent {
 *       public int $count = 0;
 *
 *       public function increment(): void {
 *           $this->count++;  // automatically persisted, automatically rendered
 *       }
 *   }
 *
 * State is keyed by component class + instance ID, so two widgets of the
 * same class on one page (or across browser tabs) keep independent state.
 *
 * The actual storage backend (session / cache / redis / array / file) is
 * resolved from the container as a StateStore — see config('htmx.state').
 */
trait HasAutoState
{
    /** Per-instance ID used to scope persisted state. */
    public ?string $instanceId = null;

    /**
     * True on the request when no persisted state was found — i.e. this
     * is the component's initial mount. Used by the lifecycle to decide
     * whether to fire onMount(), which should run once (Livewire model).
     */
    public bool $justMounted = false;

    /**
     * Resolve the request's instance ID, falling back to a freshly generated one.
     * Called by HtmxComponent::onBoot() before loadState().
     */
    public function resolveInstanceId(): void
    {
        if (!$this->persistsState()) {
            return;
        }

        // Priority 1 — a verified _hxid in the request. The wrapper that
        // mounted this component stamped it; trust it on subsequent action
        // requests. This is what makes instanceKey()-bucketed widgets keep
        // their state across clicks, since their action requests no longer
        // carry the original props.
        $param = config('htmx.instance_id_param', '_hxid');
        $request = app('request');
        $incoming = $request->get($param) ?? $request->post($param);

        if (is_string($incoming) && $incoming !== '' && $this->isVerified($incoming)) {
            $this->instanceId = $incoming;
            return;
        }

        // Priority 2 — stable instance key (logical bucket) on initial
        // mount. Components opt in by returning a non-null value from
        // instanceKey(). The key is signed so the client can't forge it.
        $key = $this->instanceKey();
        if ($key !== null && $key !== '') {
            $this->instanceId = $this->signedFor($key);
            return;
        }

        // Priority 3 — fresh random ID for a default-mounted widget.
        $this->instanceId = $this->generateInstanceId();
    }

    public function loadState(): void
    {
        if (!$this->persistsState() || $this->instanceId === null) {
            $this->justMounted = true;
            return;
        }

        $stored = $this->stateStore()->get($this->stateKey());
        if ($stored === null) {
            // No persisted snapshot — this is the initial mount.
            $this->justMounted = true;
            return;
        }

        foreach ($this->persistableProperties() as $name) {
            if (array_key_exists($name, $stored)) {
                $this->$name = $stored[$name];
            }
        }
    }

    public function persistState(): void
    {
        if (!$this->persistsState() || $this->instanceId === null) {
            return;
        }

        $snapshot = [];
        foreach ($this->persistableProperties() as $name) {
            $snapshot[$name] = $this->$name;
        }

        $store = $this->stateStore();
        $store->put($this->stateKey(), $snapshot);
        $this->trackAndPruneInstances($store);
    }

    public function persistsState(): bool
    {
        return $this->persistState && config('htmx.persist_state', true);
    }

    /**
     * Per-component LRU of recent instance IDs. When the LRU exceeds the
     * configured cap, drop the oldest entries from the store. Prevents
     * unbounded growth from abandoned widgets across many tabs / pages.
     */
    private function trackAndPruneInstances(StateStore $store): void
    {
        $indexKey = $this->instanceIndexKey();
        $index    = $store->get($indexKey) ?? [];
        $now      = time();

        unset($index[$this->instanceId]);
        $index[$this->instanceId] = $now;

        $max = (int) config('htmx.state_max_instances', 50);
        if (count($index) > $max) {
            $drop = array_slice($index, 0, count($index) - $max, true);
            foreach (array_keys($drop) as $oldId) {
                $store->forget($this->stateKeyFor($oldId));
            }
            $index = array_slice($index, -$max, null, true);
        }

        $store->put($indexKey, $index);
    }

    /**
     * Return public property names that are eligible for auto-state.
     * Excludes framework-reserved properties.
     *
     * @return string[]
     */
    private function persistableProperties(): array
    {
        $reserved = static::reservedProperties();

        $names = [];
        foreach ($this->reflectionMeta()['publicProps'] as $name) {
            if (in_array($name, $reserved, true)) continue;
            $names[] = $name;
        }

        return $names;
    }

    private function stateStore(): StateStore
    {
        return app(StateStore::class);
    }

    private function stateKey(): string
    {
        return $this->stateKeyFor($this->instanceId);
    }

    private function stateKeyFor(string $id): string
    {
        $prefix = config('htmx.session_prefix', 'htmx_');
        $shortName = strtolower($this->reflectionMeta()['shortName']);
        return $prefix . $shortName . '_' . $id . '_state';
    }

    private function instanceIndexKey(): string
    {
        $prefix = config('htmx.session_prefix', 'htmx_');
        $shortName = strtolower($this->reflectionMeta()['shortName']);
        return $prefix . $shortName . '_index';
    }

    private function generateInstanceId(): string
    {
        return $this->signedFor(bin2hex(random_bytes(6)));
    }

    /**
     * Produce a signed instance ID of the form "<raw>.<sig>".  The raw
     * portion may be a random hex token (generated) or a stable bucket
     * (from instanceKey()). The signature ties it to the app key so
     * forged IDs are rejected by isVerified().
     */
    private function signedFor(string $raw): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $raw) !== 1) {
            $raw = bin2hex(random_bytes(6));
        }
        return $raw . '.' . $this->signature($raw);
    }

    private function isVerified(string $id): bool
    {
        if (preg_match('/^([A-Za-z0-9_-]{1,64})\.([a-f0-9]{16})$/', $id, $m) !== 1) {
            return false;
        }
        return hash_equals($this->signature($m[1]), $m[2]);
    }

    private function signature(string $raw): string
    {
        $key = config('app.key', '');
        // Component class is part of the message so an ID minted for
        // Counter can't be replayed against Todo.
        $message = static::class . '|' . $raw;
        return substr(hash_hmac('sha256', $message, $key), 0, 16);
    }
}
