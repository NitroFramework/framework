<?php

namespace Nitro\Database\Model;

use Closure;
use Nitro\Support\Collection;
use Nitro\Database\Query\Paginator;
use Nitro\Database\Query\QueryBuilder;

/**
 * Model-aware wrapper around QueryBuilder. Hydrates raw rows into the
 * configured model class and propagates eager loads.
 *
 * Method dispatch:
 *   - Terminal methods (get, first, find, paginate, chunk, count, exists,
 *     pluck, value, sum, avg, min, max, update, delete, insert, insertGetId,
 *     increment, decrement, truncate, toSql, getBindings) are defined here
 *     so their return values reach the caller intact.
 *   - Builder methods (where, whereIn, orderBy, join, limit, distinct,
 *     groupBy, having, …) flow through __call which forwards to the
 *     underlying QueryBuilder and returns $this so the chain stays a
 *     ModelBuilder (preserving the model class for the eventual get/first).
 *
 * The methods below are the forwarded ones, declared so an editor can complete
 * them and static analysis does not read every where() in the framework as a
 * call to something that does not exist. Each returns the ModelBuilder, which
 * is what __call() hands back.
 *
 * @method static addSelect(Nitro\Database\Query\RawExpression|array|string ...$columns)
 * @method static crossJoin(Nitro\Database\Query\RawExpression|string $table, Closure|string|null $first = null, ?string $operator = null, ?string $second = null)
 * @method static crossJoinSub(Closure|Nitro\Database\Query\QueryBuilder|string $query, string $alias)
 * @method static distinct()
 * @method static from(Nitro\Database\Query\RawExpression|string $table)
 * @method static fromRaw(string $expression, array $bindings = [])
 * @method static fromSub(Closure|Nitro\Database\Query\QueryBuilder|string $query, string $alias)
 * @method static groupBy(string ...$columns)
 * @method static groupByRaw(string $expression, array $bindings = [])
 * @method static having(Closure|callable|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static havingBetween(string $column, array $values, string $boolean = 'AND', bool $not = false)
 * @method static havingNested(Closure|callable $callback, string $boolean = 'AND')
 * @method static havingNotBetween(string $column, array $values, string $boolean = 'AND')
 * @method static havingNotNull(array|string $columns, string $boolean = 'AND')
 * @method static havingNull(array|string $columns, string $boolean = 'AND', bool $not = false)
 * @method static havingRaw(string $expression, array $bindings = [], string $boolean = 'AND')
 * @method static inRandomOrder(string|int $seed = '')
 * @method static join(Nitro\Database\Query\RawExpression|string $table, Closure|string $first, ?string $operator = null, ?string $second = null, string $type = 'inner')
 * @method static joinSub(Closure|Nitro\Database\Query\QueryBuilder|string $query, string $alias, Closure|string $first, ?string $operator = null, ?string $second = null, string $type = 'inner')
 * @method static joinWhere(Nitro\Database\Query\RawExpression|string $table, string $first, string $operator, mixed $second, string $type = 'inner')
 * @method static latest(string $column = 'created_at')
 * @method static leftJoin(Nitro\Database\Query\RawExpression|string $table, Closure|string $first, ?string $operator = null, ?string $second = null)
 * @method static leftJoinSub(Closure|Nitro\Database\Query\QueryBuilder|string $query, string $alias, Closure|string $first, ?string $operator = null, ?string $second = null)
 * @method static leftJoinWhere(Nitro\Database\Query\RawExpression|string $table, string $first, string $operator, mixed $second)
 * @method static limit(int $limit)
 * @method static lockForUpdate()
 * @method static offset(int $offset)
 * @method static oldest(string $column = 'created_at')
 * @method static orWhere(Closure|callable|array|string $column, mixed $operator = null, mixed $value = null)
 * @method static orWhereAll(array $columns, mixed $operator = null, mixed $value = null)
 * @method static orWhereAny(array $columns, mixed $operator = null, mixed $value = null)
 * @method static orWhereBetween(string $column, array $values)
 * @method static orWhereBetweenColumns(string $column, array $columns)
 * @method static orWhereColumn(array|string $first, ?string $operator = null, ?string $second = null)
 * @method static orWhereDate(string $column, mixed $operator, mixed $value = null)
 * @method static orWhereDay(string $column, mixed $operator, mixed $value = null)
 * @method static orWhereExists(Closure|Nitro\Database\Query\QueryBuilder $callback)
 * @method static orWhereFullText(array|string $columns, string $value, array $options = [])
 * @method static orWhereIn(string $column, Closure|Nitro\Database\Query\QueryBuilder|array $values)
 * @method static orWhereIntegerInRaw(string $column, array $values)
 * @method static orWhereIntegerNotInRaw(string $column, array $values)
 * @method static orWhereJsonContains(string $column, mixed $value)
 * @method static orWhereJsonContainsKey(string $column)
 * @method static orWhereJsonDoesntContain(string $column, mixed $value)
 * @method static orWhereJsonDoesntContainKey(string $column)
 * @method static orWhereJsonDoesntOverlap(string $column, mixed $value)
 * @method static orWhereJsonLength(string $column, mixed $operator, mixed $value = null)
 * @method static orWhereJsonOverlaps(string $column, mixed $value)
 * @method static orWhereLike(string $column, string $value, bool $caseSensitive = false)
 * @method static orWhereMonth(string $column, mixed $operator, mixed $value = null)
 * @method static orWhereNone(array $columns, mixed $operator = null, mixed $value = null)
 * @method static orWhereNot(Closure|callable|array|string $column, mixed $operator = null, mixed $value = null)
 * @method static orWhereNotBetween(string $column, array $values)
 * @method static orWhereNotBetweenColumns(string $column, array $columns)
 * @method static orWhereNotExists(Closure|Nitro\Database\Query\QueryBuilder $callback)
 * @method static orWhereNotIn(string $column, Closure|Nitro\Database\Query\QueryBuilder|array $values)
 * @method static orWhereNotLike(string $column, string $value, bool $caseSensitive = false)
 * @method static orWhereNotNull(array|string $columns)
 * @method static orWhereNull(array|string $columns)
 * @method static orWhereRaw(string $expression, array $bindings = [])
 * @method static orWhereRowValues(array $columns, string $operator, array $values)
 * @method static orWhereTime(string $column, mixed $operator, mixed $value = null)
 * @method static orWhereYear(string $column, mixed $operator, mixed $value = null)
 * @method static orderBy(string $column, string $direction = 'asc')
 * @method static orderByDesc(string $column)
 * @method static orderByRaw(string $expression, array $bindings = [])
 * @method static orderBySub(Closure|Nitro\Database\Query\QueryBuilder|string $query, string $direction = 'asc')
 * @method static rightJoin(Nitro\Database\Query\RawExpression|string $table, Closure|string $first, ?string $operator = null, ?string $second = null)
 * @method static rightJoinSub(Closure|Nitro\Database\Query\QueryBuilder|string $query, string $alias, Closure|string $first, ?string $operator = null, ?string $second = null)
 * @method static rightJoinWhere(Nitro\Database\Query\RawExpression|string $table, string $first, string $operator, mixed $second)
 * @method static select(Nitro\Database\Query\RawExpression|array|string ...$columns)
 * @method static selectRaw(string $expression, array $bindings = [])
 * @method static selectSub(Closure|Nitro\Database\Query\QueryBuilder|string $query, string $alias)
 * @method static sharedLock()
 * @method static skip(int $offset)
 * @method static take(int $limit)
 * @method static where(Closure|callable|array|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static whereAll(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static whereAny(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static whereBetween(string $column, array $values, string $boolean = 'AND', bool $not = false)
 * @method static whereBetweenColumns(string $column, array $columns, string $boolean = 'AND', bool $not = false)
 * @method static whereColumn(array|string $first, ?string $operator = null, ?string $second = null, string $boolean = 'AND')
 * @method static whereDate(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND')
 * @method static whereDay(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND')
 * @method static whereExists(Closure|Nitro\Database\Query\QueryBuilder $callback, string $boolean = 'AND', bool $not = false)
 * @method static whereFullText(array|string $columns, string $value, array $options = [], string $boolean = 'AND')
 * @method static whereIn(string $column, Closure|Nitro\Database\Query\QueryBuilder|array $values, string $boolean = 'AND')
 * @method static whereIntegerInRaw(string $column, array $values, string $boolean = 'AND', bool $not = false)
 * @method static whereIntegerNotInRaw(string $column, array $values, string $boolean = 'AND')
 * @method static whereJsonContains(string $column, mixed $value, string $boolean = 'AND', bool $not = false)
 * @method static whereJsonContainsKey(string $column, string $boolean = 'AND', bool $not = false)
 * @method static whereJsonDoesntContain(string $column, mixed $value, string $boolean = 'AND')
 * @method static whereJsonDoesntContainKey(string $column, string $boolean = 'AND')
 * @method static whereJsonDoesntOverlap(string $column, mixed $value, string $boolean = 'AND')
 * @method static whereJsonLength(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND')
 * @method static whereJsonOverlaps(string $column, mixed $value, string $boolean = 'AND', bool $not = false)
 * @method static whereLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'AND', bool $not = false)
 * @method static whereMonth(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND')
 * @method static whereNested(Closure|callable $callback, string $boolean = 'AND', bool $not = false)
 * @method static whereNone(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static whereNot(Closure|callable|array|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static whereNotBetween(string $column, array $values, string $boolean = 'AND')
 * @method static whereNotBetweenColumns(string $column, array $columns, string $boolean = 'AND')
 * @method static whereNotExists(Closure|Nitro\Database\Query\QueryBuilder $callback, string $boolean = 'AND')
 * @method static whereNotIn(string $column, Closure|Nitro\Database\Query\QueryBuilder|array $values, string $boolean = 'AND')
 * @method static whereNotLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'AND')
 * @method static whereNotNull(array|string $columns, string $boolean = 'AND')
 * @method static whereNull(array|string $columns, string $boolean = 'AND', bool $not = false)
 * @method static whereRaw(string $expression, array $bindings = [], string $boolean = 'AND')
 * @method static whereRowValues(array $columns, string $operator, array $values, string $boolean = 'AND')
 * @method static whereTime(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND')
 * @method static whereYear(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND')
 * @method static withoutLimit()
 */
class ModelBuilder
{
    use Concerns\QueriesRelationships;

    protected QueryBuilder $query;
    protected string $modelClass;
    protected array $eagerLoads = [];

    public function __construct(QueryBuilder $query, string $modelClass)
    {
        $this->query = $query;
        $this->modelClass = $modelClass;
    }

    // ─── Eager Loading ────────────────────────────────────

    public function with(string|array $relations): static
    {
        $relations = is_array($relations) ? $relations : [$relations];
        $this->eagerLoads = array_merge($this->eagerLoads, $relations);
        return $this;
    }

    // ─── Hydrating Reads ──────────────────────────────────

    public function get(): Collection
    {
        $rows = $this->query->get()->all();
        $models = $this->hydrateMany($rows);

        if (!empty($this->eagerLoads) && !empty($models)) {
            RelationLoader::load($models, $this->eagerLoads);
        }

        return new Collection($models);
    }

    public function first(): ?Model
    {
        $row = $this->query->first();
        if ($row === null) return null;

        $instance = new $this->modelClass;
        $model = $instance->newFromObject($row);

        if (!empty($this->eagerLoads)) {
            $arr = [$model];
            RelationLoader::load($arr, $this->eagerLoads);
        }

        return $model;
    }

    public function find(int|string $id, string $column = 'id'): ?Model
    {
        $row = $this->query->find($id, $column);
        if ($row === null) return null;

        $instance = new $this->modelClass;
        $model = $instance->newFromObject($row);

        if (!empty($this->eagerLoads)) {
            $arr = [$model];
            RelationLoader::load($arr, $this->eagerLoads);
        }

        return $model;
    }

    /**
     * Find by key or throw. The exception is a ModelNotFoundException (still a
     * RuntimeException, so existing catches keep working) so the exception layer
     * can render it as a 404 rather than a 500.
     */
    public function findOrFail(int|string $id, string $column = 'id'): Model
    {
        $result = $this->find($id, $column);
        if (!$result) {
            throw ModelNotFoundException::forModel($this->modelClass, $id);
        }
        return $result;
    }

    public function firstOrFail(): Model
    {
        $result = $this->first();
        if (!$result) {
            throw ModelNotFoundException::forModel($this->modelClass);
        }
        return $result;
    }

    /**
     * Get the first record matching $attributes, or return a new unsaved model
     * filled with $attributes + $values (Laravel's firstOrNew).
     */
    public function firstOrNew(array $attributes, array $values = []): Model
    {
        $existing = $this->whereAttributes($attributes)->first();
        if ($existing !== null) {
            return $existing;
        }

        $instance = new $this->modelClass;
        $instance->fill(array_merge($attributes, $values));
        return $instance;
    }

    /**
     * Get the first record matching $attributes, or create and persist one from
     * $attributes + $values (Laravel's firstOrCreate).
     */
    public function firstOrCreate(array $attributes, array $values = []): Model
    {
        $existing = $this->whereAttributes($attributes)->first();
        if ($existing !== null) {
            return $existing;
        }

        return ($this->modelClass)::create(array_merge($attributes, $values));
    }

    /**
     * Update the first record matching $attributes with $values, or create it
     * (Laravel's updateOrCreate).
     */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $existing = $this->whereAttributes($attributes)->first();
        if ($existing !== null) {
            $existing->update($values);
            return $existing;
        }

        return ($this->modelClass)::create(array_merge($attributes, $values));
    }

    /**
     * Apply a set of column => value constraints on a clone (so the matching
     * query doesn't mutate $this).
     */
    protected function whereAttributes(array $attributes): static
    {
        $clone = clone $this;
        foreach ($attributes as $column => $value) {
            $clone->query->where($column, $value);
        }
        return $clone;
    }

    public function paginate(int $perPage = 15, ?int $page = null): Paginator
    {
        $paginator = $this->query->paginate($perPage, $page);
        $models = $this->hydrateMany($paginator->items());

        if (!empty($this->eagerLoads) && !empty($models)) {
            RelationLoader::load($models, $this->eagerLoads);
        }

        return new Paginator($models, $paginator->total(), $paginator->perPage(), $paginator->currentPage());
    }

    public function chunk(int $count, Closure $callback): bool
    {
        $page = 1;
        do {
            $clone = clone $this;
            $clone->query->limit($count)->offset(($page - 1) * $count);
            $results = $clone->get();

            if ($results->isEmpty()) break;
            if ($callback($results, $page) === false) return false;
            $page++;
        } while ($results->count() === $count);
        return true;
    }

    // ─── Terminal (non-hydrating) Queries ──────────────────
    // These forward to the QueryBuilder and return its raw result.

    public function count(string $column = '*'): int     { return $this->query->count($column); }
    public function exists(): bool                       { return $this->query->exists(); }
    public function doesntExist(): bool                  { return $this->query->doesntExist(); }
    public function sum(string $column): float           { return $this->query->sum($column); }
    public function avg(string $column): float           { return $this->query->avg($column); }
    public function min(string $column): mixed           { return $this->query->min($column); }
    public function max(string $column): mixed           { return $this->query->max($column); }
    public function value(string $column): mixed         { return $this->query->value($column); }
    public function pluck(string $column, ?string $key = null): array
    {
        return $this->query->pluck($column, $key);
    }

    public function insert(array $values): bool          { return $this->query->insert($values); }
    public function insertGetId(array $values): int      { return $this->query->insertGetId($values); }
    public function update(array $values): int           { return $this->query->update($values); }
    public function delete(): int                        { return $this->query->delete(); }
    public function truncate(): void                     { $this->query->truncate(); }
    public function increment(string $column, int $amount = 1, array $extra = []): int
    {
        return $this->query->increment($column, $amount, $extra);
    }
    public function decrement(string $column, int $amount = 1, array $extra = []): int
    {
        return $this->query->decrement($column, $amount, $extra);
    }

    public function toSql(): string      { return $this->query->toSql(); }
    public function getBindings(): array { return $this->query->getBindings(); }

    // ─── Internal ─────────────────────────────────────────

    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * Inline hydration — direct array iteration instead of array_map +
     * closure. Saves a closure allocation per call and a method-call frame
     * per row, which matters on large result sets.
     */
    protected function hydrateMany(array $rows): array
    {
        if (empty($rows)) return [];
        $instance = new $this->modelClass;
        $models = [];
        foreach ($rows as $row) {
            $models[] = $instance->newFromObject($row);
        }
        return $models;
    }

    // ─── Builder Forwarding ───────────────────────────────

    /**
     * Forward unknown calls. A local query scope on the model (scopeActive →
     * ->active()) wins; otherwise forward to the underlying QueryBuilder.
     * Always returns $this so chaining stays a ModelBuilder.
     */
    public function __call(string $method, array $args): static
    {
        $scope = 'scope' . ucfirst($method);
        if (method_exists($this->modelClass, $scope)) {
            // Scope signature: scopeActive(ModelBuilder $query, ...$args): void
            (new $this->modelClass)->{$scope}($this, ...$args);
            return $this;
        }

        $this->query->{$method}(...$args);
        return $this;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }
}
