<?php

namespace Nitro\Database\Model;

use Nitro\Support\Collection;
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
 */
abstract class Model
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

    // ─── Configuration ────────────────────────────────────

    protected string $table = '';
    protected string $primaryKey = 'id';
    protected bool $incrementing = true;
    protected array $fillable = [];
    protected array $guarded = ['*'];
    protected array $hidden = [];
    protected array $casts = [];
    protected bool $timestamps = true;

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
     * Classes whose boot() has already run, keyed by class name.
     *
     * Keyed rather than a flat list because booting is per concrete class: a
     * parent and its subclass each get their own boot(), and a subclass must not
     * be considered booted just because its parent was.
     *
     * @var array<class-string, true>
     */
    protected static array $booted = [];

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
        if (isset(static::$booted[static::class])) {
            return;
        }

        static::$booted[static::class] = true;

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
        static::$booted = [];
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


    // ─── Query Entry Point ────────────────────────────────

    public static function query(): ModelBuilder
    {
        $instance = new static;
        $builder = new ModelBuilder(DB::table($instance->getTable()), static::class);

        // Soft-delete global scope: hide trashed rows unless the query opts in
        // via withTrashed()/onlyTrashed() (which build a fresh, unscoped query).
        if ($instance->usesSoftDeletes()) {
            $builder->whereNull($instance->getDeletedAtColumn());
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

    // ─── Table ────────────────────────────────────────────

    public function getTable(): string
    {
        if ($this->table) return $this->table;

        // Pluralised properly: the naive "add an s" made Category look for
        // 'categorys'. Set $table explicitly for anything irregular.
        return \Nitro\Support\Str::plural(strtolower(class_basename(static::class)));
    }
}

if (!function_exists('class_basename')) {
    function class_basename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
