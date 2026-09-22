<?php

namespace Nitro\Database\Model\Concerns;

use Nitro\Database\Model\Model;
use Nitro\Database\Model\Relations\Relation;
use Nitro\Support\Carbon;
use Nitro\Support\CarbonImmutable;

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

    /**
     * Read an attribute, accessor result or relation by name.
     *
     * Resolution order: an already-loaded relation, then a getXxxAttribute()
     * accessor, then a stored column, then a relation method.
     *
     * The last step is what makes $post->user work without the caller having
     * eager-loaded it: with no column of that name, the model looks for a
     * relation method, runs it once and keeps the result.
     */
    public function getAttribute(string $key): mixed
    {
        if ($this->hasRelation($key)) {
            return $this->getRelation($key);
        }

        // Accessor: getXxxAttribute() — may be computed (no backing column).
        if ($accessor = $this->mutatorMethod('get', $key)) {
            return $this->{$accessor}($this->attributes[$key] ?? null);
        }

        if (!array_key_exists($key, $this->attributes)) {
            if (($relation = $this->resolveRelationMethod($key)) !== null) {
                $this->setRelation($key, $results = $relation->getResults());
                return $results;
            }

            return null;
        }

        // Fast path: no cast registered → return raw value without
        // touching the cache.
        if (!isset($this->getCasts()[$key])) {
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
        return $this->hasRelation($key)
            || isset($this->attributes[$key])
            || $this->resolveRelationMethod($key) !== null;
    }

    /**
     * The Relation a method of this name returns, or null if there isn't one.
     *
     * Methods declared on Model itself are excluded: property access would
     * otherwise invoke them, so reading $model->delete would delete the row.
     * Only methods a subclass adds are candidates, the method must take no
     * required arguments — anything that does is not a relation and calling it
     * would fail — and the return value must be a Relation.
     */
    protected function resolveRelationMethod(string $key): ?Relation
    {
        if ($key === '' || ! method_exists($this, $key) || method_exists(Model::class, $key)) {
            return null;
        }

        $method = new \ReflectionMethod($this, $key);

        if (! $method->isPublic() || $method->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $result = $this->{$key}();

        return $result instanceof Relation ? $result : null;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        // Snapshot BEFORE the write, not at the first getDirty().
        //
        // Hydration deliberately skips the copy — newFromObject leaves original
        // null, because most rows are only ever read — but the snapshot then
        // has to be taken the moment something is about to change. Taken later,
        // it captures the already-modified state: getDirty() compares the new
        // attributes against themselves, finds nothing, and save() writes
        // nothing. The first save after loading a row did exactly that, and
        // said it had succeeded.
        $this->ensureOriginalSnapshot();

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
        return $this->attributes[$this->getKeyName()] ?? null;
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

    /**
     * The attribute values as they were when the model was last in sync with
     * the database — before whatever is about to be written.
     *
     * Raw, exactly as stored: an enum column comes back as its backing string.
     * That is what a guard comparing "what was it before" against "what is it
     * becoming" wants, and casting first would mean handing a cast value to
     * something expecting the column.
     *
     * @return mixed The value for $key, or the whole array when $key is null.
     */
    public function getRawOriginal(?string $key = null, mixed $default = null): mixed
    {
        $original = $this->ensureOriginalSnapshot();

        if ($key === null) {
            return $original;
        }

        return array_key_exists($key, $original) ? $original[$key] : $default;
    }

    /**
     * The original attribute values, cast the way reading them would be.
     *
     *     $version->getOriginal('status')   // VersionStatus::Draft
     *
     * @return mixed The value for $key, or the whole array when $key is null.
     */
    public function getOriginal(?string $key = null, mixed $default = null): mixed
    {
        $original = $this->ensureOriginalSnapshot();

        if ($key !== null) {
            return array_key_exists($key, $original)
                ? $this->castAttribute($key, $original[$key])
                : $default;
        }

        $cast = [];

        foreach ($original as $attribute => $value) {
            $cast[$attribute] = $this->castAttribute($attribute, $value);
        }

        return $cast;
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

    /**
     * Attributes changed by the last save, keyed by name.
     *
     * @var array<string, mixed>
     */
    protected array $changes = [];

    /** Record the current dirty set as what the last save changed. */
    public function syncChanges(): static
    {
        $this->changes = $this->getDirty();

        return $this;
    }

    /** @return array<string, mixed> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Whether the last save changed any of the named attributes, or anything
     * at all when none are named.
     *
     * @param array<int, string>|string|null $attributes
     */
    public function wasChanged(array|string|null $attributes = null): bool
    {
        if ($attributes === null) {
            return $this->changes !== [];
        }

        foreach ((array) $attributes as $attribute) {
            if (array_key_exists($attribute, $this->changes)) {
                return true;
            }
        }

        return false;
    }

    /** The inverse of {@see isDirty()}. */
    public function isClean(?string $key = null): bool
    {
        return ! $this->isDirty($key);
    }

    /** Throw away unsaved changes and return to the loaded values. */
    public function discardChanges(): static
    {
        $this->attributes = $this->ensureOriginalSnapshot();
        $this->castCache = [];

        return $this;
    }

    /** Sync one attribute's original value to its current one. */
    public function syncOriginalAttribute(string $attribute): static
    {
        return $this->syncOriginalAttributes($attribute);
    }

    /** @param array<int, string>|string $attributes */
    public function syncOriginalAttributes(array|string $attributes): static
    {
        $this->ensureOriginalSnapshot();

        foreach ((array) $attributes as $attribute) {
            if (array_key_exists($attribute, $this->attributes)) {
                $this->original[$attribute] = $this->attributes[$attribute];
            }
        }

        return $this;
    }

    /** Whether an attribute's current value matches the one given. */
    public function originalIsEquivalent(string $key): bool
    {
        $original = $this->ensureOriginalSnapshot();

        if (! array_key_exists($key, $original)) {
            return false;
        }

        return $original[$key] == ($this->attributes[$key] ?? null);
    }

    // ─── Raw attribute access ─────────────────────────────

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Replace every attribute without casting or mutators.
     *
     * @param array<string, mixed> $attributes
     */
    public function setRawAttributes(array $attributes, bool $sync = false): static
    {
        $this->attributes = $attributes;
        $this->castCache = [];

        if ($sync) {
            $this->syncOriginal();
        }

        return $this;
    }

    /** Whether the model carries an attribute of this name. */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /** Read an attribute through casts and accessors. */
    public function getAttributeValue(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    /** Whether a cast is declared for an attribute. */
    public function hasCast(string $key, array|string|null $types = null): bool
    {
        $casts = $this->getCasts();

        if (! array_key_exists($key, $casts)) {
            return false;
        }

        return $types === null || in_array($casts[$key], (array) $types, true);
    }

    /**
     * Add casts on top of those the model declares.
     *
     * @param array<string, string> $casts
     */
    public function mergeCasts(array $casts): static
    {
        $this->overrides['casts'] = array_merge($this->overrides['casts'] ?? [], $casts);
        $this->castsResolved = null;
        $this->castsResolved = null;

        return $this;
    }

    /**
     * Only the named attributes, cast.
     *
     * @param  array<int, string>|string $keys
     * @return array<string, mixed>
     */
    public function only(array|string $keys): array
    {
        $result = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            $result[$key] = $this->getAttribute($key);
        }

        return $result;
    }

    /**
     * Every attribute but the named ones, cast.
     *
     * @param  array<int, string>|string $keys
     * @return array<string, mixed>
     */
    public function except(array|string $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $result = [];

        foreach (array_keys($this->attributes) as $key) {
            if (! in_array($key, $keys, true)) {
                $result[$key] = $this->getAttribute($key);
            }
        }

        return $result;
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
        if (!isset($this->getCasts()[$key]) || $value === null) return $value;

        $cast = $this->getCasts()[$key];

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
            'datetime' => Carbon::make($value),
            'immutable_datetime' => CarbonImmutable::make($value),
            // A date is a datetime at midnight, not a formatted string: callers
            // expect ->format(), ->diffForHumans() and comparisons to work on it.
            'date' => Carbon::make($value)?->startOfDay(),
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
        if ($value === null || !isset($this->getCasts()[$key])) {
            return $value;
        }

        $cast = $this->getCasts()[$key];

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

        if ($cast === 'date' && $value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
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

}
