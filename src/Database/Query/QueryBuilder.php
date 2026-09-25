<?php

namespace Nitro\Database\Query;

use Closure;
use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\Grammar;
use Nitro\Database\Query\RawExpression;
use Nitro\Database\Query\Concerns\BuildsHavings;
use Nitro\Database\Query\Concerns\BuildsJoins;
use Nitro\Database\Query\Concerns\BuildsJsonWheres;
use Nitro\Database\Query\Concerns\BuildsWhereDateClauses;
use Nitro\Database\Query\Concerns\BuildsWheres;
use Nitro\Database\Query\Concerns\CachesQueries;
use Nitro\Database\Query\Concerns\ExecutesQueries;
use Nitro\Database\Query\Concerns\HasAggregates;
use Nitro\Database\Query\Concerns\InspectsQueries;
use Nitro\Support\Conditionable;

/**
 * Fluent SQL query builder — composes and executes queries via the grammar and connection.
 */
class QueryBuilder
{
    use BuildsWheres;
    use BuildsWhereDateClauses;
    use BuildsJsonWheres;
    use BuildsHavings;
    use BuildsJoins;
    use ExecutesQueries;
    use HasAggregates;
    use InspectsQueries;
    use CachesQueries;
    use Conditionable;

    // Aliased rather than plain: this class already answers __call() for
    // whereNameAndEmail() style calls, and a dynamic where must keep winning
    // over a macro of the same name.
    use \Nitro\Support\Macroable { __call as callRegisteredMacro; }

    protected Connection $connection;
    protected Grammar $grammar;

    protected string|RawExpression $from = '';

    /**
     * Columns to select. Empty means every column, so that a builder which has
     * only been given a raw select — selectRaw('COUNT(*)'), say — asks for that
     * alone rather than for it alongside a '*' nobody wanted.
     */
    protected array $columns = [];

    /** Index hint for the FROM, where the grammar has syntax for one. */
    protected ?array $indexHint = null;

    /** Queries appended with UNION, each with its own 'all' flag. */
    protected array $unions = [];

    /**
     * Ordering and limits stated after a union was added.
     *
     * Held apart from the query's own, because once a union exists these
     * apply to the combined result while the ones already stated belong to
     * the query that started it.
     */
    protected array $unionOrders = [];

    protected ?int $unionLimitValue = null;

    protected ?int $unionOffsetValue = null;
    protected bool $distinct = false;
    protected array $wheres = [];
    /**
     * Bindings bucketed by clause so the merged array we hand to PDO
     * matches the order placeholders appear in the compiled SQL:
     *   SELECT [select] FROM JOIN [join] WHERE [where] HAVING [having]
     *   ORDER BY [order].
     *
     * 'select', 'group' and 'order' hold bindings from selectRaw(),
     * groupByRaw() and orderByRaw().
     */
    protected array $bindings = [
        'select' => [],
        'join' => [],
        'where' => [],
        'group' => [],
        'having' => [],
        'order' => [],
        'union' => [],
    ];
    protected array $joins = [];
    protected array $groups = [];
    protected array $havings = [];
    protected array $orders = [];
    protected ?int $limitValue = null;

    /** Row-lock mode for SELECT: 'update', 'share', or none. */
    protected ?string $lock = null;

    /** Whether selects go to the write connection. {@see useWritePdo()}. */
    public bool $useWritePdo = false;
    protected ?int $offsetValue = null;

    public function __construct(Connection $connection, Grammar $grammar)
    {
        $this->connection = $connection;
        $this->grammar = $grammar;
    }

    /**
     * Resolve whereColumnAndOtherColumn() style calls.
     *
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (str_starts_with($method, 'where') && strlen($method) > 5) {
            return $this->dynamicWhere($method, $parameters);
        }

        if (static::hasMacro($method)) {
            return $this->callRegisteredMacro($method, $parameters);
        }

        throw new \BadMethodCallException(
            'Call to undefined method ' . static::class . "::{$method}()."
        );
    }

    // ─── Table ─────────────────────────────────────────────

    /** A new builder on the same connection and grammar, with nothing set. */
    public function newQuery(): static
    {
        return new static($this->connection, $this->grammar);
    }

    public function table(string $table): static
    {
        $this->from = $table;
        return $this;
    }

    public function from(string|RawExpression $table): static
    {
        $this->from = $table;

        return $this;
    }

    /** Select from the results of another query, under an alias. */
    public function fromSub(Closure|QueryBuilder|string $query, string $alias): static
    {
        [$sql, $bindings] = $this->parseSubQuery($query);

        // Ahead of every other bucket: the sub-select is compiled into the
        // FROM, so its placeholders come before the ones in WHERE.
        $this->bindings['select'] = array_merge($this->bindings['select'], $bindings);

        return $this->from(new RawExpression('(' . $sql . ') AS ' . $this->grammar->wrapTable($alias)));
    }

    /** @param array<int, mixed> $bindings */
    public function fromRaw(string $expression, array $bindings = []): static
    {
        $this->bindings['select'] = array_merge($this->bindings['select'], array_values($bindings));

        return $this->from(new RawExpression($expression));
    }

    /**
     * An expression the grammar passes through untouched.
     *
     * Nothing escapes it, so never build one out of user input.
     *
     * @param array<int, mixed> $bindings
     */
    public function raw(string $expression, array $bindings = []): RawExpression
    {
        return new RawExpression($expression, $bindings);
    }

    /**
     * Ask the engine to read the named index.
     *
     * A hint, not a guarantee, and grammars that have no syntax for it ignore
     * it rather than failing — the query means the same thing either way.
     */
    public function useIndex(string $index): static
    {
        $this->indexHint = ['type' => 'hint', 'index' => $index];

        return $this;
    }

    public function forceIndex(string $index): static
    {
        $this->indexHint = ['type' => 'force', 'index' => $index];

        return $this;
    }

    public function ignoreIndex(string $index): static
    {
        $this->indexHint = ['type' => 'ignore', 'index' => $index];

        return $this;
    }

    /** @return array{type: string, index: string}|null */
    public function getIndexHint(): ?array
    {
        return $this->indexHint;
    }

    // ─── Select ────────────────────────────────────────────

    public function select(string|array|RawExpression ...$columns): static
    {
        $this->columns = [];
        $this->bindings['select'] = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                foreach ($col as $column) {
                    $this->addColumn($column);
                }
            } else {
                $this->addColumn($col);
            }
        }
        return $this;
    }

    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->columns[] = new RawExpression($expression, $bindings);
        if (!empty($bindings)) {
            foreach ($bindings as $binding) {
                $this->bindings['select'][] = $binding;
            }
        }
        return $this;
    }

    public function orderByRaw(string $expression, array $bindings = []): static
    {
        $this->orders[] = new RawExpression($expression, $bindings);
        if (!empty($bindings)) {
            foreach ($bindings as $binding) {
                $this->bindings['order'][] = $binding;
            }
        }
        return $this;
    }

    /** Select the single value another query returns, under an alias. */
    public function selectSub(Closure|QueryBuilder|string $query, string $alias): static
    {
        [$sql, $bindings] = $this->parseSubQuery($query);

        return $this->selectRaw('(' . $sql . ') AS ' . $this->grammar->wrap($alias), $bindings);
    }

    public function addSelect(string|array|RawExpression ...$columns): static
    {
        foreach ($columns as $col) {
            if (is_array($col)) {
                foreach ($col as $column) {
                    $this->addColumn($column);
                }
            } else {
                $this->addColumn($col);
            }
        }
        return $this;
    }

    /**
     * Append a single column (string or RawExpression). RawExpression
     * carries its own bindings — we lift them into bindings['select']
     * so getBindings() can build a flat array in compile order.
     */
    private function addColumn(string|RawExpression $column): void
    {
        $this->columns[] = $column;
        if ($column instanceof RawExpression && !empty($column->bindings)) {
            foreach ($column->bindings as $binding) {
                $this->bindings['select'][] = $binding;
            }
        }
    }

    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }


    // ─── Ordering & Grouping ────────────────────────────────

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $order = ['column' => $column, 'direction' => strtoupper($direction)];

        if ($this->unions !== []) {
            $this->unionOrders[] = $order;

            return $this;
        }

        $this->orders[] = $order;
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /** Order by the value a sub-select returns. */
    public function orderBySub(Closure|QueryBuilder|string $query, string $direction = 'asc'): static
    {
        [$sql, $bindings] = $this->parseSubQuery($query);

        return $this->orderByRaw('(' . $sql . ') ' . $this->grammar->validateDirection($direction), $bindings);
    }

    /**
     * Drop every order, optionally replacing them with one.
     *
     * A query built for display order is often reused for a count or an
     * aggregate, where the ORDER BY is dead weight the engine still pays for.
     */
    public function reorder(?string $column = null, string $direction = 'asc'): static
    {
        $this->orders = [];
        $this->bindings['order'] = [];

        if ($column !== null) {
            return $this->orderBy($column, $direction);
        }

        return $this;
    }

    public function reorderDesc(string $column): static
    {
        return $this->reorder($column, 'desc');
    }

    /** Order rows at random, where the grammar has a function for it. */
    public function inRandomOrder(string|int $seed = ''): static
    {
        return $this->orderByRaw($this->grammar->compileRandom((string) $seed));
    }

    /**
     * Order by an explicit list of values, keeping anything else last.
     *
     * Compiled as a CASE rather than an engine-specific function, so the same
     * call sorts the same way on every driver.
     *
     * @param array<int, mixed> $values
     */
    public function inOrderOf(string $column, array $values): static
    {
        $values = array_values($values);

        if ($values === []) {
            return $this;
        }

        $wrapped = $this->grammar->wrap($column);
        $cases = [];

        foreach ($values as $index => $value) {
            $cases[] = "WHEN {$wrapped} = ? THEN {$index}";
        }

        return $this->orderByRaw(
            'CASE ' . implode(' ', $cases) . ' ELSE ' . count($values) . ' END',
            $values
        );
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'ASC');
    }

    public function groupBy(string ...$columns): static
    {
        $this->groups = array_merge($this->groups, $columns);
        return $this;
    }

    /** @param array<int, mixed> $bindings */
    public function groupByRaw(string $expression, array $bindings = []): static
    {
        $this->groups[] = new RawExpression($expression);
        $this->bindings['group'] = array_merge($this->bindings['group'], array_values($bindings));

        return $this;
    }

    // ─── Limit & Offset ─────────────────────────────────────

    public function limit(int $limit): static
    {
        if ($this->unions !== []) {
            $this->unionLimitValue = $limit;

            return $this;
        }

        $this->limitValue = $limit;
        return $this;
    }

    public function take(int $limit): static
    {
        return $this->limit($limit);
    }

    /**
     * Lock the matched rows for update, where the driver has such a thing.
     *
     * The clause comes from the grammar, so this is a no-op on SQLite rather
     * than a syntax error: SQLite's write transaction already locks the whole
     * database, so the transaction the caller is holding IS the lock. That is
     * what lets an application that allocates a seat under lockForUpdate()
     * develop on SQLite and run on MySQL.
     *
     * Only meaningful inside a transaction. On its own it locks rows and
     * releases them immediately, which protects nothing.
     */
    public function lockForUpdate(): static
    {
        return $this->lock('update');
    }

    /** A shared (read) lock, for the same reasons. */
    public function sharedLock(): static
    {
        return $this->lock('share');
    }

    /**
     * Set the lock mode directly: 'update', 'share', or none.
     *
     * A locking read goes to the write connection: a lock taken on a read
     * replica protects nothing the write server is about to change.
     */
    public function lock(?string $mode = 'update'): static
    {
        $this->lock = $mode;

        if ($mode !== null) {
            $this->useWritePdo();
        }

        return $this;
    }

    /**
     * Run this query's selects on the write connection, where reads and
     * writes are split: for a read that must see what was just written.
     */
    public function useWritePdo(): static
    {
        $this->useWritePdo = true;

        return $this;
    }

    /**
     * Drop any LIMIT previously set.
     *
     * limit() takes an int and there is no sentinel for "none", which matters
     * when a query built for one row is reused for a batched lookup — an eager
     * load over fifty parents must not inherit the limit(1) that was right for
     * one of them.
     */
    public function withoutLimit(): static
    {
        $this->limitValue = null;
        return $this;
    }

    public function offset(int $offset): static
    {
        if ($this->unions !== []) {
            $this->unionOffsetValue = $offset;

            return $this;
        }

        $this->offsetValue = $offset;
        return $this;
    }

    public function skip(int $offset): static
    {
        return $this->offset($offset);
    }

    /** Limit and offset for one page of results. */
    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(max(0, $page - 1) * $perPage)->limit($perPage);
    }

    /**
     * One page of results, taken after a known id.
     *
     * Cheaper than an offset on a large table, which the engine has to count
     * through row by row, and stable while rows are being inserted.
     */
    public function forPageAfterId(int $perPage = 15, int|string|null $lastId = 0, string $column = 'id'): static
    {
        $this->orders = array_values(array_filter(
            $this->orders,
            static fn ($order): bool => ! is_array($order) || ($order['column'] ?? null) !== $column
        ));

        if ($lastId !== null) {
            $this->where($column, '>', $lastId);
        }

        return $this->orderBy($column, 'asc')->limit($perPage);
    }

    /** The same, walking backwards. */
    public function forPageBeforeId(int $perPage = 15, int|string|null $lastId = 0, string $column = 'id'): static
    {
        $this->orders = array_values(array_filter(
            $this->orders,
            static fn ($order): bool => ! is_array($order) || ($order['column'] ?? null) !== $column
        ));

        if ($lastId !== null) {
            $this->where($column, '<', $lastId);
        }

        return $this->orderBy($column, 'desc')->limit($perPage);
    }

    // ─── Unions ─────────────────────────────────────────────

    /**
     * Append another query's results to this one's.
     *
     * The appended query keeps its own WHERE and its own bindings; only the
     * ordering and the limit of the outer query apply to the combined result.
     */
    public function union(Closure|QueryBuilder $query, bool $all = false): static
    {
        if ($query instanceof Closure) {
            $callback = $query;
            $query = $this->newQuery();
            $callback($query);
        }

        $this->unions[] = ['query' => $query, 'all' => $all];
        $this->bindings['union'] = array_merge($this->bindings['union'], $query->getBindings());

        return $this;
    }

    public function unionAll(Closure|QueryBuilder $query): static
    {
        return $this->union($query, true);
    }

    /** @return array<int, array{query: QueryBuilder, all: bool}> */
    public function getUnions(): array
    {
        return $this->unions;
    }

    // ─── SQL Output ─────────────────────────────────────────

    public function toSql(): string
    {
        return $this->grammar->compileSelect($this);
    }

    public function getBindings(): array
    {
        // Flat array in compile-order (select → join → where → group →
        // having → order). Avoiding nested loops here matters because
        // get/first/count all call this on the hot path.
        return [
            ...$this->bindings['select'],
            ...$this->bindings['join'],
            ...$this->bindings['where'],
            ...$this->bindings['group'],
            ...$this->bindings['having'],
            ...$this->bindings['order'],
            ...$this->bindings['union'],
        ];
    }

    public function getRawBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Clone the builder and drop the first WHERE clause + its bindings.
     * Used by the relation layer for eager loading: every relation pre-applies
     * a 'parent.id = ?' filter for the single-parent case, and eager loading
     * needs to swap that for 'parent.id IN (?, ?, …)'. Doing this without
     * reflection keeps eager loading cheap.
     *
     * Only basic, in, not_in, between WHEREs are supported as the leading
     * clause (which is what every relation uses). Others raise so misuse
     * is loud rather than silently corrupting bindings.
     */
    public function cloneWithoutFirstWhere(): static
    {
        $clone = clone $this;
        if (empty($clone->wheres)) {
            return $clone;
        }
        $first = array_shift($clone->wheres);

        $consume = match ($first['type'] ?? null) {
            'basic'   => 1,
            'in', 'not_in' => count($first['values'] ?? []),
            'between' => 2,
            'null', 'not_null', 'column' => 0,
            default   => null,
        };
        if ($consume === null) {
            throw new \LogicException(
                "cloneWithoutFirstWhere only supports basic/in/between/null/column WHEREs, got: " . ($first['type'] ?? 'unknown')
            );
        }
        if ($consume > 0) {
            $clone->bindings['where'] = array_slice($clone->bindings['where'], $consume);
        }
        return $clone;
    }

    // ─── Internal Access ────────────────────────────────────

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    // ─── Getters (used by Grammar) ──────────────────────────

    public function getFrom(): string|RawExpression
    {
        return $this->from;
    }
    /** The requested row-lock mode, if any. Read by the grammar. */
    public function getLock(): ?string
    {
        return $this->lock;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }
    public function isDistinct(): bool
    {
        return $this->distinct;
    }
    public function getWheres(): array
    {
        return $this->wheres;
    }
    public function getJoins(): array
    {
        return $this->joins;
    }
    public function getGroups(): array
    {
        return $this->groups;
    }
    public function getHavings(): array
    {
        return $this->havings;
    }
    public function getOrders(): array
    {
        return $this->orders;
    }
    public function getLimit(): ?int
    {
        return $this->limitValue;
    }

    public function getOffset(): ?int
    {
        return $this->offsetValue;
    }

    public function getLimitValue(): ?int
    {
        return $this->limitValue;
    }
    public function getOffsetValue(): ?int
    {
        return $this->offsetValue;
    }

    /** @return array<int, array{column: string, direction: string}> */
    public function getUnionOrders(): array
    {
        return $this->unionOrders;
    }

    public function getUnionLimitValue(): ?int
    {
        return $this->unionLimitValue;
    }

    public function getUnionOffsetValue(): ?int
    {
        return $this->unionOffsetValue;
    }
}
