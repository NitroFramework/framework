<?php

namespace Nitro\Database\Query;

use Closure;
use Nitro\Database\Connection;
use Nitro\Database\Query\Grammar\Grammar;
use Nitro\Database\Query\RawExpression;
use Nitro\Database\Query\Concerns\BuildsJoins;
use Nitro\Database\Query\Concerns\BuildsWheres;
use Nitro\Database\Query\Concerns\ExecutesQueries;
use Nitro\Database\Query\Concerns\HasAggregates;

/**
 * Fluent SQL query builder — composes and executes queries via the grammar and connection.
 */
class QueryBuilder
{
    use BuildsWheres;
    use BuildsJoins;
    use ExecutesQueries;
    use HasAggregates;

    protected Connection $connection;
    protected Grammar $grammar;

    protected string $from = '';
    protected array $columns = ['*'];
    protected bool $distinct = false;
    protected array $wheres = [];
    /**
     * Bindings bucketed by clause so the merged array we hand to PDO
     * matches the order placeholders appear in the compiled SQL:
     *   SELECT [select] FROM JOIN [join] WHERE [where] HAVING [having]
     *   ORDER BY [order].
     *
     * 'select' and 'order' hold bindings from selectRaw()/orderByRaw().
     */
    protected array $bindings = [
        'select' => [],
        'join' => [],
        'where' => [],
        'having' => [],
        'order' => [],
    ];
    protected array $joins = [];
    protected array $groups = [];
    protected array $havings = [];
    protected array $orders = [];
    protected ?int $limitValue = null;
    protected ?int $offsetValue = null;

    public function __construct(Connection $connection, Grammar $grammar)
    {
        $this->connection = $connection;
        $this->grammar = $grammar;
    }

    // ─── Table ─────────────────────────────────────────────

    public function table(string $table): static
    {
        $this->from = $table;
        return $this;
    }

    public function from(string $table): static
    {
        return $this->table($table);
    }

    // ─── Select ────────────────────────────────────────────

    public function select(string|array|RawExpression ...$columns): static
    {
        $this->columns = [];
        $this->bindings['select'] = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                foreach ($col as $c) {
                    $this->addColumn($c);
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
            foreach ($bindings as $b) {
                $this->bindings['select'][] = $b;
            }
        }
        return $this;
    }

    public function orderByRaw(string $expression, array $bindings = []): static
    {
        $this->orders[] = new RawExpression($expression, $bindings);
        if (!empty($bindings)) {
            foreach ($bindings as $b) {
                $this->bindings['order'][] = $b;
            }
        }
        return $this;
    }

    public function addSelect(string|array|RawExpression ...$columns): static
    {
        foreach ($columns as $col) {
            if (is_array($col)) {
                foreach ($col as $c) {
                    $this->addColumn($c);
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
            foreach ($column->bindings as $b) {
                $this->bindings['select'][] = $b;
            }
        }
    }

    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    // ─── Conditional ────────────────────────────────────────

    public function when(mixed $condition, Closure $callback, ?Closure $default = null): static
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($default) {
            $default($this, $condition);
        }
        return $this;
    }

    // ─── Ordering & Grouping ────────────────────────────────

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orders[] = ['column' => $column, 'direction' => strtoupper($direction)];
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
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

    public function having(string $column, ?string $operator = null, mixed $value = null): static
    {
        // Same operator/value split as where(): only treat the operator slot as
        // the value when just two args were given, so having('c','>',null) keeps
        // its operator instead of collapsing to 'c = ">"'.
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        $this->havings[] = ['column' => $column, 'operator' => $operator, 'value' => $value];
        $this->bindings['having'][] = $value;
        return $this;
    }

    // ─── Limit & Offset ─────────────────────────────────────

    public function limit(int $limit): static
    {
        $this->limitValue = $limit;
        return $this;
    }

    public function take(int $limit): static
    {
        return $this->limit($limit);
    }

    public function offset(int $offset): static
    {
        $this->offsetValue = $offset;
        return $this;
    }

    public function skip(int $offset): static
    {
        return $this->offset($offset);
    }

    // ─── SQL Output ─────────────────────────────────────────

    public function toSql(): string
    {
        return $this->grammar->compileSelect($this);
    }

    public function getBindings(): array
    {
        // Flat array in compile-order (select → join → where → having →
        // order). Avoiding nested loops here matters because get/first/
        // count all call this on the hot path.
        return [
            ...$this->bindings['select'],
            ...$this->bindings['join'],
            ...$this->bindings['where'],
            ...$this->bindings['having'],
            ...$this->bindings['order'],
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

    public function getFrom(): string
    {
        return $this->from;
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
    public function getLimitValue(): ?int
    {
        return $this->limitValue;
    }
    public function getOffsetValue(): ?int
    {
        return $this->offsetValue;
    }
}
