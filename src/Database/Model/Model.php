<?php

namespace Nitro\Database\Model;

use Nitro\Support\Collection;
use Nitro\Support\Str;
use Nitro\Database\DB;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Model\Concerns\HasAttributes;
use Nitro\Database\Model\Concerns\HasCrud;
use Nitro\Database\Model\Concerns\HasEvents;
use Nitro\Database\Model\Concerns\HasRelationships;
use Nitro\Database\Model\Concerns\InteractsWithModelState;
use Nitro\Database\Model\Concerns\SerializesData;

/**
 * Base class for Active Record models.
 *
 * Composes attribute access/casting, CRUD, relationships, events and serialization
 * (from the Concerns traits) over a database table. Application models extend this
 * class; the table name is inferred from the class unless overridden.
 *
 * Configuration a subclass supplies — $table, $primaryKey, $incrementing,
 * $fillable, $guarded, $hidden, $casts, $timestamps — is deliberately not
 * declared here. PHP requires a redeclaration to match the parent's type, or its
 * absence, exactly, so declaring them would force one style on every model in
 * every application. Undeclared, a subclass may type them or not.
 *
 * This class therefore reads that configuration only through the accessors
 * below, each supplying the default a declaration would have carried. Setters
 * write to $overrides rather than to the properties themselves.
 */
abstract class Model implements \ArrayAccess, \JsonSerializable
{
    use HasAttributes;
    use HasCrud;
    use HasEvents;
    use HasRelationships;
    use InteractsWithModelState;
    use SerializesData;

    /**
     * Whether this model soft-deletes. The {@see SoftDeletes} trait overrides
     * this to true, which makes query() hide trashed rows by default.
     */
    public function usesSoftDeletes(): bool
    {
        return false;
    }

    /** The column holding the creation time, or null to keep none. */
    public const CREATED_AT = 'created_at';

    /** The column holding the last-update time, or null to keep none. */
    public const UPDATED_AT = 'updated_at';

    /**
     * Configuration set at runtime through the setters below.
     *
     * The setters cannot write to $table, $fillable and the rest: those belong
     * to the subclass, which may not have declared the one being set. Writes
     * land here instead and every accessor consults this first, so a setter
     * takes effect whether or not the model declares the property.
     *
     * @var array<string, mixed>
     */
    protected array $overrides = [];

    // ─── Identity ─────────────────────────────────────────

    public function setTable(string $table): static
    {
        $this->overrides['table'] = $table;

        return $this;
    }

    /** Prefix a column with this model's table name. */
    public function qualifyColumn(string $column): string
    {
        return str_contains($column, '.') ? $column : $this->getTable() . '.' . $column;
    }

    /**
     * @param  array<int, string> $columns
     * @return array<int, string>
     */
    public function qualifyColumns(array $columns): array
    {
        return array_map(fn (string $column): string => $this->qualifyColumn($column), $columns);
    }

    public function getQualifiedKeyName(): string
    {
        return $this->qualifyColumn($this->getKeyName());
    }

    public function setKeyName(string $key): static
    {
        $this->overrides['primaryKey'] = $key;

        return $this;
    }

    public function getKeyType(): string
    {
        return $this->overrides['keyType'] ?? $this->keyType ?? 'int';
    }

    public function setKeyType(string $type): static
    {
        $this->overrides['keyType'] = $type;

        return $this;
    }

    public function setIncrementing(bool $value): static
    {
        $this->overrides['incrementing'] = $value;

        return $this;
    }

    /** The foreign key another table would use to point at this model. */
    public function getForeignKey(): string
    {
        return $this->guessForeignKey();
    }

    public function getPerPage(): int
    {
        return $this->overrides['perPage'] ?? $this->perPage ?? 15;
    }

    public function setPerPage(int $perPage): static
    {
        $this->overrides['perPage'] = $perPage;

        return $this;
    }

    /** Whether two models are the same record of the same class. */
    public function is(?self $model): bool
    {
        return $model !== null
            && $this->getKey() !== null
            && $this->getKey() == $model->getKey()
            && static::class === $model::class
            && $this->getTable() === $model->getTable();
    }

    public function isNot(?self $model): bool
    {
        return ! $this->is($model);
    }

    // ─── Route model binding ──────────────────────────────

    /** The column a route parameter is matched against. */
    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    public function getRouteKey(): mixed
    {
        return $this->getAttribute($this->getRouteKeyName());
    }

    /** Find the record a route parameter refers to. */
    public function resolveRouteBinding(mixed $value, ?string $field = null): ?static
    {
        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /**
     * Find a nested record, scoped to its parent.
     *
     * A route parameter is singular — {comment} — while the relation holding
     * it is usually plural, so the plural is tried first.
     *
     * @param string $childType The child's route parameter name, e.g. 'comment'.
     */
    public function resolveChildRouteBinding(string $childType, mixed $value, ?string $field = null): mixed
    {
        $name = Str::camel($childType);

        $relation = $this->resolveRelationMethod(Str::plural($name))
            ?? $this->resolveRelationMethod($name);

        if ($relation === null) {
            return null;
        }

        $child = new ($relation->getModelClass())();

        return $relation->where($field ?? $child->getRouteKeyName(), $value)->first();
    }

    // ─── Query factories ──────────────────────────────────

    /** A new query builder for this model, with its scopes applied. */
    public function newQuery(): ModelBuilder
    {
        return static::query();
    }

    /** A new query builder with no global scopes applied. */
    public function newModelQuery(): ModelBuilder
    {
        return static::query();
    }

    public function newQueryWithoutScopes(): ModelBuilder
    {
        return static::query();
    }

    /** Begin a query on another connection. */
    public static function on(?string $connection = null): ModelBuilder
    {
        $instance = new static();

        if ($connection !== null) {
            $instance->setConnection($connection);
        }

        return $instance->newQuery();
    }

    /**
     * Wrap a set of results in the collection type this model uses.
     *
     * @param array<int, mixed> $models
     */
    public function newCollection(array $models = []): \Nitro\Support\Collection
    {
        return new \Nitro\Support\Collection($models);
    }

    /**
     * A new, unsaved instance of this model.
     *
     * @param array<string, mixed> $attributes
     */
    public function newInstance(array $attributes = [], bool $exists = false): static
    {
        $instance = new static();
        $instance->setRawAttributes($attributes);
        $instance->exists = $exists;

        return $instance;
    }

    // ─── Connection ───────────────────────────────────────

    /** The connection this model reads and writes, or null for the default. */
    protected ?string $connectionName = null;

    public function getConnectionName(): ?string
    {
        return $this->connection ?? $this->connectionName;
    }

    public function setConnection(?string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    public function getConnection(): mixed
    {
        return \Nitro\Database\DB::connection($this->getConnectionName());
    }

    // ─── ArrayAccess ──────────────────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return $this->getAttribute((string) $offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[(string) $offset], $this->castCache[(string) $offset]);
    }

    // ─── Events ───────────────────────────────────────────

    /** Whether model events are currently suppressed. */
    protected static bool $eventsMuted = false;

    /** Run a callback with this model's events suppressed. */
    public static function withoutEvents(callable $callback): mixed
    {
        if (static::$eventsMuted) {
            return $callback();
        }

        static::$eventsMuted = true;

        try {
            return $callback();
        } finally {
            static::$eventsMuted = false;
        }
    }

    public static function eventsAreMuted(): bool
    {
        return static::$eventsMuted;
    }

    /**
     * The table this model reads and writes.
     *
     * Falls back to the pluralised, lower-cased class name. Pluralisation is
     * irregular-aware; set $table explicitly for anything it gets wrong.
     */
    public function getTable(): string
    {
        if (isset($this->overrides['table'])) {
            return $this->overrides['table'];
        }

        if (! empty($this->table)) {
            return $this->table;
        }

        return \Nitro\Support\Str::plural(strtolower(class_basename(static::class)));
    }

    public function getKeyName(): string
    {
        return $this->overrides['primaryKey'] ?? $this->primaryKey ?? 'id';
    }

    public function getIncrementing(): bool
    {
        return $this->overrides['incrementing'] ?? $this->incrementing ?? true;
    }

    /** @return array<int, string> */
    public function getFillable(): array
    {
        return $this->overrides['fillable'] ?? $this->fillable ?? [];
    }

    /** @return array<int, string> */
    public function getGuarded(): array
    {
        return $this->overrides['guarded'] ?? $this->guarded ?? ['*'];
    }

    /** @return array<int, string> */
    public function getHidden(): array
    {
        return $this->overrides['hidden'] ?? $this->hidden ?? [];
    }

    public function usesTimestamps(): bool
    {
        return $this->timestamps ?? true;
    }

    /**
     * The model's cast map, merged from the $casts property and a casts()
     * method if the model defines one. The method wins on conflict.
     *
     * Memoized: getAttribute() consults this on every read of a cast attribute.
     *
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        if ($this->castsResolved !== null) {
            return $this->castsResolved;
        }

        $casts = $this->casts ?? [];

        if (method_exists($this, 'casts')) {
            $casts = array_merge($casts, $this->casts());
        }

        $casts = array_merge($casts, $this->overrides['casts'] ?? []);

        return $this->castsResolved = $casts;
    }

    /** Memo for {@see getCasts()}. Null until first resolution. */
    protected ?array $castsResolved = null;

    // ─── Instance State ───────────────────────────────────

    protected array $attributes = [];
    protected ?array $original = null;
    protected bool $exists = false;

    /**
     * Per-attribute cast result memo. Lazily populated on first access,
     * invalidated on __set, cleared on refresh/hydrate. Saves repeat
     * `new DateTime($val)` and `json_decode($val)` on every read.
     */
    protected array $castCache = [];

    /**
     * New model, optionally mass-assigned from $attributes (respecting
     * $fillable/$guarded) — Laravel's `new User([...])`. Hydration from the DB
     * goes through newFromObject(), not this, so it bypasses fillable.
     */
    public function __construct(array $attributes = [])
    {
        $this->bootIfNotBooted();

        if ($attributes !== []) {
            $this->fill($attributes);
        }
    }

    // ─── Booting ──────────────────────────────────────────

    /**
     * Run this class's one-time boot, if it hasn't already.
     *
     * Every route into a model goes through the constructor — query(), find(),
     * newFromObject(), `new User` — so this is the one place that needs to ask.
     * The guard matters: boot() registers event listeners, and running it twice
     * would fire every saving()/deleting() hook twice per save.
     */
    protected function bootIfNotBooted(): void
    {
        if (ModelState::hasBooted(static::class)) {
            return;
        }

        ModelState::markBooted(static::class);

        static::boot();
    }

    /**
     * Boot the model: first each trait's boot hook, then the class's own.
     *
     * A trait named SoftDeletes may define bootSoftDeletes(), which is how a
     * trait registers listeners or global constraints without every model that
     * uses it having to remember to call something.
     *
     * Override booted(), not this — boot() is the plumbing.
     */
    protected static function boot(): void
    {
        foreach (class_uses_recursive(static::class) as $trait) {
            $method = 'boot' . class_basename($trait);

            if (method_exists(static::class, $method)) {
                forward_static_call([static::class, $method]);
            }
        }

        static::booted();
    }

    /**
     * The model's own boot hook — override this.
     *
     * This is where model-level invariants belong, as opposed to the UI or a
     * service that happens to write the row. A guard registered here holds for a
     * seeder, an import and a console command alike:
     *
     *     protected static function booted(): void
     *     {
     *         static::updating(fn (self $version) => $version->isDraft());
     *     }
     */
    protected static function booted(): void
    {
        // intentionally empty
    }

    /**
     * Forget that a class was booted, so the next instance boots again.
     *
     * For tests that flush event listeners between cases: without this the model
     * stays marked booted and never re-registers the listeners that were just
     * flushed, and every guard silently stops applying.
     */
    public static function clearBootedModels(): void
    {
        ModelState::clearBooted();
    }

    // ─── Polymorphic type names ───────────────────────────

    /**
     * Short name => model class, for the value stored in a `*_type` column.
     *
     * Storing 'course' instead of 'App\Models\Course' is worth setting up on
     * day one: the class name ends up in thousands of rows, and without a map
     * the day somebody moves or renames that class is the day every one of
     * those rows stops resolving. A map makes it a one-line edit.
     *
     *     Model::enforceMorphMap(['course' => Course::class]);
     *
     * @var array<string, class-string<Model>>
     */
    protected static array $morphMap = [];

    /** @param array<string, class-string<Model>> $map */
    public static function enforceMorphMap(array $map): void
    {
        static::$morphMap = array_merge(static::$morphMap, $map);
    }

    /** @return array<string, class-string<Model>> */
    public static function morphMap(): array
    {
        return static::$morphMap;
    }

    /** The value to store in a `*_type` column for this model. */
    public function getMorphClass(): string
    {
        $alias = array_search(static::class, static::$morphMap, true);

        return $alias === false ? static::class : $alias;
    }

    /** The class a stored `*_type` value refers to. */
    public static function morphedClass(string $type): string
    {
        return static::$morphMap[$type] ?? $type;
    }


    // ─── Global Scopes ────────────────────────────────────

    /**
     * Registered global scopes, keyed by model class then identifier.
     *
     * @var array<class-string, array<string, Scope|\Closure>>
     */
    protected static array $globalScopes = [];

    /**
     * Register a constraint applied to every query for this model.
     *
     * A Scope instance is keyed by its class name; a closure needs an
     * explicit string identifier so it can be removed again.
     *
     * @param  string|Scope    $identifier Identifier, or a Scope keyed by its class.
     * @param  Scope|\Closure|null $scope  Implementation when $identifier is a string.
     * @throws \InvalidArgumentException When a closure is given no identifier.
     */
    public static function addGlobalScope(string|Scope $identifier, Scope|\Closure|null $scope = null): void
    {
        if ($identifier instanceof Scope) {
            static::$globalScopes[static::class][$identifier::class] = $identifier;

            return;
        }

        if ($scope === null) {
            throw new \InvalidArgumentException(
                "Global scope [{$identifier}] was registered without an implementation."
            );
        }

        static::$globalScopes[static::class][$identifier] = $scope;
    }

    public static function hasGlobalScope(string|Scope $identifier): bool
    {
        return static::resolveGlobalScope($identifier) !== null;
    }

    /** @return Scope|\Closure|null */
    public static function resolveGlobalScope(string|Scope $identifier): Scope|\Closure|null
    {
        $key = $identifier instanceof Scope ? $identifier::class : $identifier;

        return static::$globalScopes[static::class][$key] ?? null;
    }

    /** @return array<string, Scope|\Closure> */
    public static function getGlobalScopes(): array
    {
        return static::$globalScopes[static::class] ?? [];
    }

    // ─── Query Entry Point ────────────────────────────────

    public static function query(): ModelBuilder
    {
        return static::buildQuery();
    }

    /**
     * A query with every global scope applied except those named.
     *
     * @param string|Scope ...$identifiers Scopes to leave off.
     */
    public static function withoutGlobalScope(string|Scope ...$identifiers): ModelBuilder
    {
        $excluded = array_map(
            static fn (string|Scope $identifier): string => $identifier instanceof Scope
                ? $identifier::class
                : $identifier,
            $identifiers
        );

        return static::buildQuery($excluded);
    }

    /** A query with no global scopes applied. */
    public static function withoutGlobalScopes(): ModelBuilder
    {
        return static::buildQuery(null);
    }

    /**
     * Build a query, applying global scopes.
     *
     * @param array<int, string>|null $excluded Identifiers to skip, or null to skip all.
     */
    protected static function buildQuery(?array $excluded = []): ModelBuilder
    {
        $instance = new static;
        $builder = new ModelBuilder(DB::table($instance->getTable()), static::class);

        if ($excluded === null) {
            return $builder;
        }

        foreach (static::getGlobalScopes() as $identifier => $scope) {
            if (in_array($identifier, $excluded, true)) {
                continue;
            }

            if ($scope instanceof Scope) {
                $scope->apply($builder, $instance);

                continue;
            }

            $scope($builder, $instance);
        }

        return $builder;
    }

    /**
     * Invalidate every cached query for this model's table. Writes through the
     * builder do this automatically; call it after a raw-SQL write the builder
     * couldn't see, or to force-refresh.
     */
    public static function flushQueryCache(): void
    {
        QueryBuilder::bumpCacheVersion((new static)->getTable());
    }

    // ─── Static Proxies ───────────────────────────────────

    public static function find(int|string $id): ?static
    {
        return static::query()->find($id);
    }

    public static function findOrFail(int|string $id): static
    {
        return static::query()->findOrFail($id);
    }

    public static function all(): Collection
    {
        return static::query()->get();
    }

    public static function first(): ?static
    {
        return static::query()->first();
    }

    public static function count(string $column = '*'): int
    {
        return static::query()->count($column);
    }

    public static function exists(): bool
    {
        return static::query()->exists();
    }

    public static function where(string|\Closure|callable $column, mixed $operator = null, mixed $value = null): ModelBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    /**
     * Catch-all static dispatch. The result type depends on which
     * ModelBuilder method we hit:
     *   - Terminal (count/exists/sum/pluck/value/etc.) returns its value.
     *   - Builder (where/orderBy/etc.) returns the ModelBuilder.
     * ModelBuilder defines terminals explicitly so this works out without
     * losing return values to the magic-call layer.
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        return static::query()->{$method}(...$args);
    }

    // ─── Hydration ────────────────────────────────────────

    public function newFromObject(object $obj): static
    {
        $model = new static;
        $model->attributes = (array) $obj;
        $model->original = null; // lazy snapshot; built on first getDirty()/save()
        $model->exists = true;
        return $model;
    }

}

if (!function_exists('class_basename')) {
    function class_basename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
