<?php

namespace Nitro\Database\Query\Concerns;

use Closure;
use Nitro\Support\Collection;
use Nitro\Database\Query\Paginator;
use Nitro\Database\Query\RawExpression;

/**
 * Query builder concern: executes the built query (get/first/insert/update/delete).
 */
trait ExecutesQueries
{
    // ─── Read ─────────────────────────────────────────────

    public function get(): Collection
    {
        $sql = $this->grammar->compileSelect($this);
        $results = $this->connection->select($sql, $this->getBindings());
        return new Collection($results);
    }

    /**
     * Return the first row as a plain object, or null. Does NOT mutate the
     * builder — applying limit(1) on a clone so a subsequent ->get() on the
     * same builder still returns the full result set.
     */
    public function first(): ?object
    {
        $clone = clone $this;
        $clone->limitValue = 1;
        return $clone->get()->first();
    }

    /**
     * Look up a single row by primary key. Non-mutating: subsequent calls
     * on the same builder don't accumulate `WHERE id = ?` clauses.
     */
    public function find(int|string $id, string $column = 'id'): ?object
    {
        return (clone $this)->where($column, $id)->first();
    }

    public function value(string $column): mixed
    {
        $clone = clone $this;
        $clone->columns = [$column];
        $clone->bindings['select'] = [];
        $result = $clone->first();
        if (!$result) return null;
        // Column may be qualified (table.col) or aliased — fetch by the
        // unqualified tail so $row->{$column} still works.
        $key = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
        return $result->{$key} ?? null;
    }

    public function pluck(string $column, ?string $key = null): array
    {
        $clone = clone $this;
        $clone->columns = $key ? [$key, $column] : [$column];
        $clone->bindings['select'] = [];

        $sql = $clone->grammar->compileSelect($clone);
        $results = $clone->connection->select($sql, $clone->getBindings());

        // Strip table-qualifier when present so we read the right property.
        $colKey = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
        $keyKey = $key !== null
            ? (str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key)
            : null;

        $plucked = [];
        if ($keyKey !== null) {
            foreach ($results as $row) {
                $plucked[$row->{$keyKey}] = $row->{$colKey};
            }
        } else {
            foreach ($results as $row) {
                $plucked[] = $row->{$colKey};
            }
        }
        return $plucked;
    }

    /**
     * EXISTS — uses a SELECT-1 wrapper (see Grammar::compileExistsInner)
     * so MySQL can short-circuit. Null-safe in case the underlying selectOne
     * returns no row.
     */
    public function exists(): bool
    {
        $sql = $this->grammar->compileExists($this);
        $result = $this->connection->selectOne($sql, $this->getBindings());
        return $result !== null && (bool) ($result->exists ?? false);
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    // ─── Insert ───────────────────────────────────────────

    public function insert(array $values): bool
    {
        if (empty($values)) return true;
        if (!is_array(reset($values))) $values = [$values];

        $sql = $this->grammar->compileInsert($this, $values);
        $bindings = [];
        foreach ($values as $record) {
            foreach ($record as $v) {
                $bindings[] = $v;
            }
        }
        return $this->connection->insert($sql, $bindings);
    }

    /**
     * Single-row insert returning the generated ID. Flat assoc array only
     * — calling with multi-row data raises rather than silently binding
     * nested arrays to PDO (which would throw a less clear error deep
     * inside the driver).
     */
    public function insertGetId(array $values): int
    {
        if (empty($values)) return 0;
        if (is_array(reset($values))) {
            throw new \InvalidArgumentException(
                'insertGetId() expects a single record. Use insert() for batch inserts.'
            );
        }

        $sql = $this->grammar->compileInsertGetId($this, $values);
        return $this->connection->insertGetId($sql, array_values($values));
    }

    // ─── Update ───────────────────────────────────────────

    public function update(array $values): int
    {
        $sql = $this->grammar->compileUpdate($this, $values);
        $bindings = [];
        foreach ($values as $v) {
            if (!$v instanceof RawExpression) {
                $bindings[] = $v;
            }
        }
        // WHERE bindings follow SET bindings in the placeholder order.
        foreach ($this->bindings['where'] as $b) {
            $bindings[] = $b;
        }
        return $this->connection->update($sql, $bindings);
    }

    public function upsert(array $values, array $uniqueBy, array $update): int
    {
        if (!is_array(reset($values))) $values = [$values];

        $sql = $this->grammar->compileUpsert($this, $values, $uniqueBy, $update);

        $bindings = [];
        foreach ($values as $record) {
            foreach ($record as $v) {
                $bindings[] = $v;
            }
        }
        // Assoc-form $update may carry value bindings — Sequential-form
        // (col names referencing new.col) carries none.
        $isAssoc = !empty($update) && array_keys($update) !== range(0, count($update) - 1);
        if ($isAssoc) {
            foreach ($update as $v) {
                if (!$v instanceof RawExpression) {
                    $bindings[] = $v;
                }
            }
        }
        return $this->connection->update($sql, $bindings);
    }

    public function increment(string $column, int $amount = 1, array $extra = []): int
    {
        $wrapped = $this->grammar->wrap($column);
        $values = array_merge([$column => new RawExpression("{$wrapped} + {$amount}")], $extra);
        $sql = $this->grammar->compileUpdate($this, $values);
        $bindings = [];
        foreach ($values as $v) {
            if (!$v instanceof RawExpression) {
                $bindings[] = $v;
            }
        }
        foreach ($this->bindings['where'] as $b) {
            $bindings[] = $b;
        }
        return $this->connection->update($sql, $bindings);
    }

    public function decrement(string $column, int $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    // ─── Delete ───────────────────────────────────────────

    public function delete(): int
    {
        $sql = $this->grammar->compileDelete($this);
        return $this->connection->delete($sql, $this->bindings['where']);
    }

    public function truncate(): void
    {
        $sql = $this->grammar->compileTruncate($this->from);
        $this->connection->statement($sql);
    }

    // ─── Chunking & Pagination ────────────────────────────

    public function chunk(int $count, Closure $callback): bool
    {
        $page = 1;
        do {
            $clone = clone $this;
            $clone->limitValue = $count;
            $clone->offsetValue = ($page - 1) * $count;
            $results = $clone->get();
            if ($results->isEmpty()) break;
            if ($callback($results, $page) === false) return false;
            $page++;
        } while ($results->count() === $count);
        return true;
    }

    public function paginate(int $perPage = 15, ?int $page = null): Paginator
    {
        // The page comes from the caller, else from the request via the
        // Paginator resolver — the query layer never reads $_GET itself.
        $page = max(1, $page ?? Paginator::resolveCurrentPage());

        // Count clone strips orders + raw-select bindings — ORDER BY is
        // pointless for COUNT(*), and dropping it lets MySQL skip a sort.
        $countQuery = clone $this;
        $countQuery->orders = [];
        $countQuery->bindings['order'] = [];
        $countQuery->columns = ['*'];
        $countQuery->bindings['select'] = [];
        $countQuery->limitValue = null;
        $countQuery->offsetValue = null;
        $total = $countQuery->count();

        $page = (int) $page;
        $resultsQuery = clone $this;
        $resultsQuery->limitValue = $perPage;
        $resultsQuery->offsetValue = ($page - 1) * $perPage;
        $results = $resultsQuery->get();

        return new Paginator($results->all(), $total, $perPage, $page);
    }
}
