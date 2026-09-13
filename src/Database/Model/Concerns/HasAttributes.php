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

        $this->attributes[$key] = $this->castValueForStorage($key, $value);
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

    /**
     * Resolved custom-cast instances, keyed by class → cast declaration. A caster
     * is stateless by contract, so one instance answers for every model of the
     * class and we never pay for the reflection twice.
     *
     * @var array<string, array<string, object>>
     */
    protected static array $casterCache = [];

    protected function castAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key]) || $value === null) return $value;

        $cast = $this->casts[$key];

        // A cast naming a class is either a backed enum or a CastsAttributes
        // implementation; both are resolved by resolveClassCast().
        if ($this->isClassCast($cast)) {
            return $this->castToClass($key, $cast, $value);
        }

        return match ($cast) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : $value,
            'object' => is_string($value) ? json_decode($value) : $value,
            'datetime' => $value instanceof \DateTimeInterface ? $value : new \DateTime($value),
            'immutable_datetime' => $value instanceof \DateTimeImmutable
                ? $value
                : new \DateTimeImmutable($value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value),
            'date' => $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : (new \DateTime($value))->format('Y-m-d'),
            'timestamp' => is_numeric($value) ? (int) $value : strtotime($value),
            default => $value,
        };
    }

    /**
     * Read side of a class cast. A backed enum comes back as a case; anything
     * else goes through the caster's get().
     *
     * tryFrom(), not from(): a column holding a value the enum no longer has a
     * case for is a data problem, and blowing up on every read of an unrelated
     * attribute is a poor way to report it. Null surfaces it where it is used.
     */
    protected function castToClass(string $key, string $cast, mixed $value): mixed
    {
        [$class, $arguments] = $this->parseClassCast($cast);

        if (is_subclass_of($class, \BackedEnum::class)) {
            return $value instanceof $class ? $value : $class::tryFrom($value);
        }

        return $this->resolveCaster($cast, $class, $arguments)
            ->get($this, $key, $value, $this->attributes);
    }

    /**
     * Encode a value for storage when its cast requires it. array/json/object
     * casts must be serialized to a JSON string on write — PDO can't bind a PHP
     * array/object, and the read-side cast (castAttribute) decodes it back. A
     * backed enum stores its ->value; a CastsAttributes cast delegates to set().
     * Other casts (and already-encoded strings) pass through untouched.
     */
    protected function castValueForStorage(string $key, mixed $value): mixed
    {
        if ($value === null || !isset($this->casts[$key])) {
            return $value;
        }

        $cast = $this->casts[$key];

        if ($this->isClassCast($cast)) {
            return $this->castFromClass($key, $cast, $value);
        }

        if (in_array($cast, ['array', 'json', 'object'], true)
            && (is_array($value) || is_object($value))) {
            return json_encode($value);
        }

        if (in_array($cast, ['datetime', 'immutable_datetime'], true)
            && $value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /**
     * Write side of a class cast.
     *
     * A caster returning an array is writing several columns at once (a money
     * amount and its currency, say); those are merged into the attributes and
     * the key's own value is taken from the array when present. Anything else
     * is a single-column value.
     */
    protected function castFromClass(string $key, string $cast, mixed $value): mixed
    {
        [$class, $arguments] = $this->parseClassCast($cast);

        if (is_subclass_of($class, \BackedEnum::class)) {
            return $value instanceof \BackedEnum ? $value->value : $value;
        }

        $set = $this->resolveCaster($cast, $class, $arguments)
            ->set($this, $key, $value, $this->attributes);

        if (is_array($set)) {
            foreach ($set as $column => $columnValue) {
                if ($column !== $key) {
                    $this->attributes[$column] = $columnValue;
                    unset($this->castCache[$column]);
                }
            }
            return $set[$key] ?? null;
        }

        return $set;
    }

    /**
     * The cast names the engine handles itself. Checked before class_exists(),
     * which would otherwise put 'int' and 'array' through the autoloader on
     * every single attribute read.
     */
    protected const BUILT_IN_CASTS = [
        'int' => true, 'integer' => true, 'float' => true, 'double' => true,
        'string' => true, 'bool' => true, 'boolean' => true, 'array' => true,
        'json' => true, 'object' => true, 'datetime' => true, 'date' => true,
        'immutable_datetime' => true, 'timestamp' => true,
    ];

    /** Whether a cast declaration names a class rather than a built-in type. */
    protected function isClassCast(string $cast): bool
    {
        if (isset(self::BUILT_IN_CASTS[$cast])) {
            return false;
        }

        return class_exists($this->parseClassCast($cast)[0]);
    }

    /**
     * Split 'Some\Caster:arg,arg' into its class and argument list. The colon is
     * only a separator after the class name, so a leading namespace separator or
     * a Windows-ish path never confuses it.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    protected function parseClassCast(string $cast): array
    {
        if (!str_contains($cast, ':')) {
            return [$cast, []];
        }

        [$class, $arguments] = explode(':', $cast, 2);

        return [$class, explode(',', $arguments)];
    }

    /**
     * Resolve (and memoize) the caster for a cast declaration.
     *
     * @param  array<int, string>  $arguments
     */
    protected function resolveCaster(string $cast, string $class, array $arguments): \Nitro\Database\Model\Contracts\CastsAttributes
    {
        $caster = self::$casterCache[static::class][$cast] ??= new $class(...$arguments);

        if (!$caster instanceof \Nitro\Database\Model\Contracts\CastsAttributes) {
            throw new \InvalidArgumentException(
                "Cast [{$class}] on " . static::class . "::\${$cast} must implement "
                . \Nitro\Database\Model\Contracts\CastsAttributes::class . '.'
            );
        }

        return $caster;
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
