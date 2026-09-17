<?php

namespace Nitro\Database\Query\Concerns;

use Closure;
use Nitro\Database\Query\JoinClause;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Query\RawExpression;

/**
 * Query builder concern: JOIN clause construction.
 */
trait BuildsJoins
{
    /**
     * Join another table.
     *
     * The second argument is either the first column of a simple comparison,
     * or a callback that receives the join's own clause builder and may add as
     * many conditions to the ON as it likes.
     */
    public function join(string|RawExpression $table, string|Closure $first, ?string $operator = null, ?string $second = null, string $type = 'inner'): static
    {
        $this->joins[] = $this->newJoinClause($type, $table, $first, $operator, $second);

        return $this;
    }

    public function leftJoin(string|RawExpression $table, string|Closure $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function rightJoin(string|RawExpression $table, string|Closure $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * Join every row of another table.
     *
     * With a condition it is an inner join written as a cross one, which some
     * engines accept and others optimise identically; without, it is the
     * cartesian product.
     */
    public function crossJoin(string|RawExpression $table, string|Closure|null $first = null, ?string $operator = null, ?string $second = null): static
    {
        if ($first === null) {
            $this->joins[] = [
                'type' => 'cross',
                'table' => $table,
                'wheres' => [],
            ];

            return $this;
        }

        return $this->join($table, $first, $operator, $second, 'cross');
    }

    /** Join on a condition that compares a column against a value. */
    public function joinWhere(string|RawExpression $table, string $first, string $operator, mixed $second, string $type = 'inner'): static
    {
        return $this->join($table, function (JoinClause $join) use ($first, $operator, $second): void {
            $join->where($first, $operator, $second);
        }, null, null, $type);
    }

    public function leftJoinWhere(string|RawExpression $table, string $first, string $operator, mixed $second): static
    {
        return $this->joinWhere($table, $first, $operator, $second, 'left');
    }

    public function rightJoinWhere(string|RawExpression $table, string $first, string $operator, mixed $second): static
    {
        return $this->joinWhere($table, $first, $operator, $second, 'right');
    }

    // ─── Sub-select joins ─────────────────────────────────

    /** Join the results of another query, under an alias. */
    public function joinSub(Closure|QueryBuilder|string $query, string $alias, string|Closure $first, ?string $operator = null, ?string $second = null, string $type = 'inner'): static
    {
        [$sql, $bindings] = $this->parseSubQuery($query);

        $this->bindings['join'] = array_merge($this->bindings['join'], $bindings);

        return $this->join(
            new RawExpression('(' . $sql . ') AS ' . $this->grammar->wrapTable($alias)),
            $first,
            $operator,
            $second,
            $type
        );
    }

    public function leftJoinSub(Closure|QueryBuilder|string $query, string $alias, string|Closure $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->joinSub($query, $alias, $first, $operator, $second, 'left');
    }

    public function rightJoinSub(Closure|QueryBuilder|string $query, string $alias, string|Closure $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->joinSub($query, $alias, $first, $operator, $second, 'right');
    }

    public function crossJoinSub(Closure|QueryBuilder|string $query, string $alias): static
    {
        [$sql, $bindings] = $this->parseSubQuery($query);

        $this->bindings['join'] = array_merge($this->bindings['join'], $bindings);

        return $this->crossJoin(new RawExpression('(' . $sql . ') AS ' . $this->grammar->wrapTable($alias)));
    }

    // ─── Internals ────────────────────────────────────────

    /**
     * Build one join entry, gathering its conditions and lifting their
     * bindings into the join bucket so they stay ahead of the WHERE's.
     *
     * @return array<string, mixed>
     */
    protected function newJoinClause(string $type, string|RawExpression $table, string|Closure|null $first, ?string $operator, ?string $second): array
    {
        $clause = new JoinClause($this, $type, $table);

        if ($first instanceof Closure) {
            $first($clause);
        } elseif ($first !== null) {
            $clause->on($first, $operator, $second);
        }

        $this->bindings['join'] = array_merge($this->bindings['join'], $clause->getBindings());

        return [
            'type' => $type,
            'table' => $table,
            'wheres' => $clause->getWheres(),
        ];
    }

    /**
     * Reduce a sub-query to SQL and bindings.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected function parseSubQuery(Closure|QueryBuilder|string $query): array
    {
        if (is_string($query)) {
            return [$query, []];
        }

        if ($query instanceof Closure) {
            $callback = $query;
            $query = $this->newQuery();
            $callback($query);
        }

        return [$query->toSql(), $query->getBindings()];
    }
}
