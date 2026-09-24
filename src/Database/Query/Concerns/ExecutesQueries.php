<?php

namespace Nitro\Database\Query\Concerns;

use Closure;
use Nitro\Support\Collection;
use Nitro\Database\Query\Exceptions\RecordsNotFoundException;
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
        return $this->cacheResult('get', function () {
            $sql = $this->grammar->compileSelect($this);
            $results = $this->connection->select($sql, $this->getBindings());
            return new Collection($results);
        });
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
     * The first row, insisting there is one.
     *
     * first() returns null, which a caller then has to check — and the check
     * is forgotten often enough that the failure surfaces as "property on
     * null" somewhere further down instead of here.
     *
     * @throws RecordsNotFoundException
     */
    public function firstOrFail(): object
    {
        $row = $this->first();

        if ($row === null) {
            throw new RecordsNotFoundException(
                'No matching row was found for the query on [' . ($this->from ?? 'the table') . '].'
            );
        }

        return $row;
    }

    /**
     * A copy of this builder, so a base query can be reused.
     *
     *     $base = DB::table('orders')->where('status', 'paid');
     *     $thisMonth = $base->clone()->whereToday('created_at');
     *     $total     = $base->clone()->sum('total');
     *
     * Without it the second call sees the first one's extra clauses.
     */
    public function clone(): static
    {
        return clone $this;
    }

    /**
     * Hand the builder to a callback and return whatever it gives back.
     *
     * For a query that is built in pieces by several functions, without each
     * of them having to return the builder.
     */
    public function pipe(Closure $callback): mixed
    {
        return $callback($this);
    }

    /**
     * Walk the rows in chunks, collecting what the callback returns.
     *
     * chunk() is for doing something to each row; this is for turning them
     * into something, without holding every row in memory at once.
     *
     * @return Collection
     */
    public function chunkMap(Closure $callback, int $count = 1000): Collection
    {
        $mapped = [];

        $this->chunk($count, function (Collection $rows) use ($callback, &$mapped): void {
            foreach ($rows as $row) {
                $mapped[] = $callback($row);
            }
        });

        return new Collection($mapped);
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

    /** Run a callback when the query matches nothing. */
    public function existsOr(Closure $callback): mixed
    {
        return $this->exists() ? true : $callback();
    }

    public function doesntExistOr(Closure $callback): mixed
    {
        return $this->doesntExist() ? true : $callback();
    }

    /** Look up a row by key, falling back to a callback when there is none. */
    public function findOr(int|string $id, Closure $callback, string $column = 'id'): mixed
    {
        return $this->find($id, $column) ?? $callback();
    }

    /**
     * The one row the query matches.
     *
     * Raises rather than picking a winner when the query matches several,
     * because a caller asking for "the" row has assumed there is only one.
     *
     * @throws \RuntimeException When the query matches no rows, or more than one.
     */
    public function sole(): object
    {
        $clone = clone $this;
        $clone->limitValue = 2;
        $results = $clone->get();

        if ($results->isEmpty()) {
            throw new \RuntimeException('No records found for [' . $this->from . '].');
        }

        if ($results->count() > 1) {
            throw new \RuntimeException('Multiple records found for [' . $this->from . '].');
        }

        return $results->first();
    }

    /** The value of one column of the single row the query matches. */
    public function soleValue(string $column): mixed
    {
        $clone = clone $this;
        $clone->columns = [$column];
        $clone->bindings['select'] = [];

        $key = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

        return $clone->sole()->{$key} ?? null;
    }

    /**
     * The value of a raw expression, evaluated by the engine.
     *
     * @param array<int, mixed> $bindings
     */
    public function rawValue(string $expression, array $bindings = []): mixed
    {
        $clone = clone $this;
        $clone->columns = [];
        $clone->bindings['select'] = [];
        $clone->selectRaw("({$expression}) AS `value`", $bindings);

        $result = $clone->first();

        return $result->value ?? null;
    }

    /** Join one column's values into a string. */
    public function implode(string $column, string $glue = ''): string
    {
        return implode($glue, $this->pluck($column));
    }

    // ─── Insert ───────────────────────────────────────────

    public function insert(array $values): bool
    {
        if (empty($values)) return true;
        if (!is_array(reset($values))) $values = [$values];

        $sql = $this->grammar->compileInsert($this, $values);
        $bindings = [];
        foreach ($values as $record) {
            foreach ($record as $value) {
                $bindings[] = $value;
            }
        }
        $result = $this->connection->insert($sql, $bindings);
        static::bumpCacheVersion((string) $this->from);
        return $result;
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
        $id = $this->connection->insertGetId($sql, array_values($values));
        static::bumpCacheVersion((string) $this->from);
        return $id;
    }

    /**
     * Insert rows, skipping the ones that collide with an existing key.
     *
     * @param  array<mixed> $values
     * @return int Rows inserted.
     */
    public function insertOrIgnore(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        if (!is_array(reset($values))) {
            $values = [$values];
        }

        $sql = $this->grammar->compileInsertOrIgnore($this, $values);

        $bindings = [];

        foreach ($values as $record) {
            foreach ($record as $value) {
                $bindings[] = $value;
            }
        }

        $inserted = $this->connection->update($sql, $bindings);
        static::bumpCacheVersion((string) $this->from);

        return $inserted;
    }

    /**
     * Insert the rows another query returns.
     *
     * The rows never leave the engine, which is what makes this worth
     * reaching for over reading them out and writing them back.
     *
     * @param  array<int, string> $columns
     * @return int Rows inserted.
     */
    public function insertUsing(array $columns, Closure|\Nitro\Database\Query\QueryBuilder|string $query): int
    {
        [$sql, $bindings] = $this->parseSubQuery($query);

        $inserted = $this->connection->update(
            $this->grammar->compileInsertUsing($this, $columns, $sql),
            $bindings
        );

        static::bumpCacheVersion((string) $this->from);

        return $inserted;
    }

    // ─── Update ───────────────────────────────────────────

    public function update(array $values): int
    {
        $sql = $this->grammar->compileUpdate($this, $values);
        $bindings = [];
        foreach ($values as $value) {
            if (!$value instanceof RawExpression) {
                $bindings[] = $value;
            }
        }
        // WHERE bindings follow SET bindings in the placeholder order.
        foreach ($this->bindings['where'] as $binding) {
            $bindings[] = $binding;
        }
        $affected = $this->connection->update($sql, $bindings);
        static::bumpCacheVersion((string) $this->from);
        return $affected;
    }

    public function upsert(array $values, array $uniqueBy, array $update): int
    {
        if (!is_array(reset($values))) $values = [$values];

        $sql = $this->grammar->compileUpsert($this, $values, $uniqueBy, $update);

        $bindings = [];
        foreach ($values as $record) {
            foreach ($record as $value) {
                $bindings[] = $value;
            }
        }
        // Assoc-form $update may carry value bindings — Sequential-form
        // (col names referencing new.col) carries none.
        $isAssoc = !empty($update) && array_keys($update) !== range(0, count($update) - 1);
        if ($isAssoc) {
            foreach ($update as $value) {
                if (!$value instanceof RawExpression) {
                    $bindings[] = $value;
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
        foreach ($values as $value) {
            if (!$value instanceof RawExpression) {
                $bindings[] = $value;
            }
        }
        foreach ($this->bindings['where'] as $binding) {
            $bindings[] = $binding;
        }
        return $this->connection->update($sql, $bindings);
    }

    public function decrement(string $column, int $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    /**
     * Increment several columns in one statement.
     *
     * @param array<string, int> $columns
     * @param array<string, mixed> $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $values = [];

        foreach ($columns as $column => $amount) {
            $wrapped = $this->grammar->wrap($column);
            $values[$column] = new RawExpression("{$wrapped} + " . (int) $amount);
        }

        $values = array_merge($values, $extra);

        $sql = $this->grammar->compileUpdate($this, $values);
        $bindings = [];

        foreach ($values as $value) {
            if (!$value instanceof RawExpression) {
                $bindings[] = $value;
            }
        }

        foreach ($this->bindings['where'] as $binding) {
            $bindings[] = $binding;
        }

        return $this->connection->update($sql, $bindings);
    }

    /**
     * @param array<string, int> $columns
     * @param array<string, mixed> $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        return $this->incrementEach(
            array_map(static fn (int $amount): int => -$amount, $columns),
            $extra
        );
    }

    /**
     * Update the row matching $attributes, or insert one if there is none.
     *
     * Two statements rather than one upsert, so it works on a table with no
     * unique index over those columns — at the cost of a race that a unique
     * index and upsert() would close.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        $existing = (clone $this)->where($attributes);

        if (! $existing->exists()) {
            return $this->insert(array_merge($attributes, $values));
        }

        if ($values === []) {
            return true;
        }

        return $existing->update($values) >= 0;
    }

    // ─── Delete ───────────────────────────────────────────

    public function delete(): int
    {
        $sql = $this->grammar->compileDelete($this);
        $affected = $this->connection->delete($sql, $this->bindings['where']);
        static::bumpCacheVersion((string) $this->from);
        return $affected;
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

    /**
     * Walk the results in batches, keyed by an ever-increasing id.
     *
     * Steadier than chunk() on a table being written to: an offset shifts
     * under you when rows are inserted or deleted mid-walk, and rows get
     * visited twice or skipped. A key comparison cannot drift that way.
     */
    public function chunkById(int $count, Closure $callback, string $column = 'id', ?string $alias = null): bool
    {
        $alias ??= $column;
        $lastId = null;
        $page = 1;

        do {
            $clone = clone $this;
            $clone->forPageAfterId($count, $lastId, $column);
            $results = $clone->get();

            if ($results->isEmpty()) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $last = $results->last();
            $lastId = is_object($last) ? ($last->{$alias} ?? null) : null;

            if ($lastId === null) {
                throw new \RuntimeException(
                    "chunkById() requires the column [{$alias}] to be present in every row."
                );
            }

            $page++;
        } while ($results->count() === $count);

        return true;
    }

    /** Hand each row to a callback, reading them a chunk at a time. */
    public function each(Closure $callback, int $count = 1000): bool
    {
        return $this->chunk($count, static function (Collection $results) use ($callback): ?bool {
            foreach ($results as $key => $row) {
                if ($callback($row, $key) === false) {
                    return false;
                }
            }

            return null;
        });
    }

    /**
     * Read the results one row at a time, straight off the connection.
     *
     * Constant memory regardless of how many rows match, which is what makes
     * an export of a million rows possible at all; the trade is that the
     * statement stays open for as long as the caller iterates.
     *
     * @return \Generator<int, object>
     */
    public function cursor(): \Generator
    {
        return $this->connection->cursor(
            $this->grammar->compileSelect($this),
            $this->getBindings()
        );
    }

    /**
     * The row count the pagination is over.
     *
     * Ordering and the page window are dropped: neither changes the count,
     * and an ORDER BY the engine still has to satisfy is pure cost.
     */
    public function getCountForPagination(): int
    {
        $countQuery = clone $this;
        $countQuery->orders = [];
        $countQuery->bindings['order'] = [];
        $countQuery->columns = ['*'];
        $countQuery->bindings['select'] = [];
        $countQuery->limitValue = null;
        $countQuery->offsetValue = null;

        return $countQuery->count();
    }

    /**
     * A page of results without counting the whole table.
     *
     * One row beyond the page is fetched and discarded; its presence is what
     * answers "is there a next page", which is all a previous/next pager
     * needs and is far cheaper than a COUNT over millions of rows.
     */
    public function simplePaginate(int $perPage = 15, ?int $page = null): Paginator
    {
        $page = max(1, (int) ($page ?? Paginator::resolveCurrentPage()));

        $clone = clone $this;
        $clone->limitValue = $perPage + 1;
        $clone->offsetValue = ($page - 1) * $perPage;

        $results = $clone->get()->all();
        $hasMore = count($results) > $perPage;

        if ($hasMore) {
            array_pop($results);
        }

        // Without a count there is no true total; the paginator is told just
        // enough to know whether a next page exists.
        $total = ($page - 1) * $perPage + count($results) + ($hasMore ? 1 : 0);

        return new Paginator($results, $total, $perPage, $page);
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
