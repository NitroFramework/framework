<?php

namespace Nitro\Database\Model\Concerns;

/**
 * Model concern: attribute storage, access, casting and dirty tracking.
 */
trait HasAttributes
{
    /**
     * Per-class cache of accessor/mutator method lookups. Keyed by
     * class → "get|set" → attribute → method name (or false when none), so the
     * studly() + method_exists() work happens once per (class, key), not on
     * every attribute read.
     *
     * @var array<string, array<string, array<string, string|false>>>
     */
    protected static array $accessorCache = [];

    // ─── Attribute Access ─────────────────────────────────

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function getAttribute(string $key): mixed
    {
        // Relations take priority.
        if ($this->hasRelation($key)) {
            return $this->getRelation($key);
        }

        // Accessor: getXxxAttribute() — may be computed (no backing column).
        if ($accessor = $this->mutatorMethod('get', $key)) {
            return $this->{$accessor}($this->attributes[$key] ?? null);
        }

        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }

        // Fast path: no cast registered → return raw value without
        // touching the cache.
        if (!isset($this->casts[$key])) {
            return $this->attributes[$key];
        }

        // Memoized cast — `$post->created_at->format(...)` then a second
        // read won't reinstantiate DateTime.
        if (array_key_exists($key, $this->castCache)) {
            return $this->castCache[$key];
        }
        return $this->castCache[$key] = $this->castAttribute($key, $this->attributes[$key]);
    }

    public function __isset(string $key): bool
    {
        return $this->hasRelation($key) || isset($this->attributes[$key]);
    }

    public function setAttribute(string $key, mixed $value): static
    {
        // Mutator: setXxxAttribute($value) writes to $this->attributes itself.
        if ($mutator = $this->mutatorMethod('set', $key)) {
            $this->{$mutator}($value);
            unset($this->castCache[$key]);
            return $this;
        }

        $this->attributes[$key] = $value;
        unset($this->castCache[$key]);
        return $this;
    }

    /**
     * Resolve (and cache) the accessor/mutator method for a key, or null when
     * the model doesn't define one. $type is 'get' or 'set'.
     */
    protected function mutatorMethod(string $type, string $key): ?string
    {
        $class = static::class;

        if (!isset(self::$accessorCache[$class][$type][$key])) {
            $method = $type . $this->studlyKey($key) . 'Attribute';
            self::$accessorCache[$class][$type][$key] = method_exists($this, $method) ? $method : false;
        }

        return self::$accessorCache[$class][$type][$key] ?: null;
    }

    /** snake_case / kebab-case → StudlyCase for accessor method names. */
    protected function studlyKey(string $key): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
    }

    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    // ─── Dirty Tracking ──────────────────────────────────

    /**
     * Lazily snapshot the original attribute state. We avoid copying on
     * hydration (newFromObject sets original=null) because most reads
     * never call getDirty(); when they do, we capture once and reuse.
     */
    protected function ensureOriginalSnapshot(): array
    {
        if ($this->original === null) {
            $this->original = $this->attributes;
        }
        return $this->original;
    }

    public function getDirty(): array
    {
        $original = $this->ensureOriginalSnapshot();
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $original)) {
                $dirty[$key] = $value;
                continue;
            }
            // Loose equality — PDO returns numeric columns as strings
            // (especially with EMULATE_PREPARES=false on driver versions
            // that don't honor stringify=false), so '5' == 5 should NOT
            // be flagged dirty after a no-op write.
            if ($original[$key] != $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    public function isDirty(?string $key = null): bool
    {
        if ($key !== null) {
            $original = $this->ensureOriginalSnapshot();
            if (!array_key_exists($key, $this->attributes)) return false;
            if (!array_key_exists($key, $original)) return true;
            return $original[$key] != $this->attributes[$key];
        }
        return !empty($this->getDirty());
    }

    public function syncOriginal(): static
    {
        $this->original = $this->attributes;
        return $this;
    }

    // ─── Casting ──────────────────────────────────────────

    protected function castAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key]) || $value === null) return $value;

        return match ($this->casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : $value,
            'object' => is_string($value) ? json_decode($value) : $value,
            'datetime' => $value instanceof \DateTimeInterface ? $value : new \DateTime($value),
            'date' => $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : (new \DateTime($value))->format('Y-m-d'),
            'timestamp' => is_numeric($value) ? (int) $value : strtotime($value),
            default => $value,
        };
    }

    // ─── Mass Assignment ──────────────────────────────────

    public function fill(array $attributes): static
    {
        // Route through setAttribute so mutators (setXxxAttribute) run on
        // mass assignment, matching Laravel.
        foreach ($this->filterFillable($attributes) as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    protected function filterFillable(array $attributes): array
    {
        // An explicit $fillable whitelist always wins.
        if (!empty($this->fillable)) {
            return array_intersect_key($attributes, array_flip($this->fillable));
        }

        // No whitelist: $guarded is the blacklist. The '*' sentinel means the
        // model is "totally guarded" — nothing is mass-assignable until the
        // developer declares $fillable (Laravel's safe default). Without this
        // the sentinel would only exclude a column literally named '*'.
        if (in_array('*', $this->guarded, true)) {
            return [];
        }

        return array_diff_key($attributes, array_flip($this->guarded));
    }
}
