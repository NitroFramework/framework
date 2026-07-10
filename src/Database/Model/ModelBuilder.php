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
 */
class ModelBuilder
{
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

    public function findOrFail(int|string $id, string $column = 'id'): Model
    {
        $result = $this->find($id, $column);
        if (!$result) {
            throw new \RuntimeException($this->modelClass . " not found with {$column}: {$id}");
        }
        return $result;
    }

    public function firstOrFail(): Model
    {
        $result = $this->first();
        if (!$result) {
            throw new \RuntimeException("No {$this->modelClass} matches the query.");
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
