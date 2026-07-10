<?php

namespace Nitro\Database\Model;

use Nitro\Support\Collection;
use Nitro\Database\DB;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Model\Concerns\HasAttributes;
use Nitro\Database\Model\Concerns\HasCrud;
use Nitro\Database\Model\Concerns\HasEvents;
use Nitro\Database\Model\Concerns\HasRelationships;
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
     * New model, optionally mass-assigned from $attributes (respecting
     * $fillable/$guarded) — Laravel's `new User([...])`. Hydration from the DB
     * goes through newFromObject(), not this, so it bypasses fillable.
     */
    public function __construct(array $attributes = [])
    {
        if ($attributes !== []) {
            $this->fill($attributes);
        }
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
        $class = class_basename(static::class);
        return strtolower($class) . 's';
    }
}

if (!function_exists('class_basename')) {
    function class_basename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
