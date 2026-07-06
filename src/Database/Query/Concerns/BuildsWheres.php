<?php

namespace Nitro\Database\Query\Concerns;

use Closure;
use InvalidArgumentException;
use Nitro\Database\Query\RawExpression;

/**
 * Query builder concern: WHERE clause construction.
 */
trait BuildsWheres
{
    public function where(string|Closure|callable $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        if ($column instanceof Closure || (is_callable($column) && !is_string($column))) {
            return $this->whereNested($column, $boolean);
        }

        // Assume '=' only when the operator slot is really the value (2-arg
        // shorthand). The shift is gated purely on the argument count — never
        // on $value being null — so an explicit where('col', '!=', null) keeps
        // its operator instead of collapsing to 'col = "!="'. (Laravel's
        // prepareValueAndOperator uses this exact rule.)
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        // A null value maps to IS NULL / IS NOT NULL. Only equality/inequality
        // operators are meaningful against null; anything else is a bug.
        if ($value === null) {
            return match ($operator) {
                '=', '<=>' => $this->whereNull($column, $boolean),
                '!=', '<>' => $this->whereNotNull($column, $boolean),
                default    => throw new InvalidArgumentException(
                    "Illegal operator [{$operator}] with a null value. Use whereNull()/whereNotNull()."
                ),
            };
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];
        $this->bindings['where'][] = $value;

        return $this;
    }

    /**
     * Resolve the (value, operator) pair for a where clause. When only the
     * column and one more argument are supplied, that argument is the value and
     * the operator defaults to '='. Otherwise the pair is returned untouched.
     * Mirrors Laravel's Builder::prepareValueAndOperator so callers that build
     * their own boolean (orWhere, …) can prepare before delegating to where().
     */
    protected function prepareValueAndOperator(mixed $value, mixed $operator, bool $useDefault = false): array
    {
        return $useDefault ? [$operator, '='] : [$value, $operator];
    }

    public function orWhere(string|Closure|callable $column, mixed $operator = null, mixed $value = null): static
    {
        // Prepare here (on our own arg count) so where() — which always receives
        // four arguments from us — never re-guesses the operator/value split.
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];
        $this->bindings['where'] = array_merge($this->bindings['where'], $values);
        return $this;
    }

    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'not_in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];
        $this->bindings['where'] = array_merge($this->bindings['where'], $values);
        return $this;
    }

    public function whereNull(string $column, string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => $boolean,
        ];
        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'not_null',
            'column' => $column,
            'boolean' => $boolean,
        ];
        return $this;
    }

    public function whereBetween(string $column, array $values, string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];
        $this->bindings['where'][] = $values[0];
        $this->bindings['where'][] = $values[1];
        return $this;
    }

    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'column',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => $boolean,
        ];
        return $this;
    }

    public function whereExists(Closure $callback, string $boolean = 'AND', bool $not = false): static
    {
        $subQuery = new static($this->connection, $this->grammar);
        $callback($subQuery);

        $this->wheres[] = [
            'type' => $not ? 'not_exists' : 'exists',
            'query' => $subQuery->toSql(),
            'boolean' => $boolean,
        ];
        $this->bindings['where'] = array_merge($this->bindings['where'], $subQuery->getBindings());
        return $this;
    }

    public function whereRaw(string $expression, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'raw',
            'expression' => new RawExpression($expression),
            'boolean' => $boolean,
        ];
        $this->bindings['where'] = array_merge($this->bindings['where'], $bindings);
        return $this;
    }

    protected function whereNested(Closure|callable $callback, string $boolean): static
    {
        $subQuery = new static($this->connection, $this->grammar);
        $subQuery->from($this->from);
        $callback($subQuery);

        if (!empty($subQuery->wheres)) {
            $nestedSql = '';
            foreach ($subQuery->wheres as $i => $where) {
                $compiled = $this->grammar->compileWhere($where);
                $nestedSql .= ($i === 0) ? $compiled : " {$where['boolean']} {$compiled}";
            }

            $this->wheres[] = [
                'type' => 'raw',
                'expression' => new RawExpression("({$nestedSql})"),
                'boolean' => $boolean,
            ];
            $this->bindings['where'] = array_merge($this->bindings['where'], $subQuery->bindings['where']);
        }
        return $this;
    }

    // ─── Or Variants ──────────────────────────────────────

    public function orWhereIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function orWhereNotIn(string $column, array $values): static
    {
        return $this->whereNotIn($column, $values, 'OR');
    }

    public function orWhereNull(string $column): static
    {
        return $this->whereNull($column, 'OR');
    }

    public function orWhereNotNull(string $column): static
    {
        return $this->whereNotNull($column, 'OR');
    }

    public function orWhereBetween(string $column, array $values): static
    {
        return $this->whereBetween($column, $values, 'OR');
    }
}
