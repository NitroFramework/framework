<?php

namespace Nitro\Database\Query\Concerns;

use Closure;
use InvalidArgumentException;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Query\RawExpression;

/**
 * Query builder concern: WHERE clause construction.
 */
trait BuildsWheres
{
    /**
     * Add a basic where clause.
     *
     * The column may also be an array of conditions, and the value a closure or
     * another builder, in which case the clause compiles against a sub-select.
     */
    public function where(string|array|Closure|callable $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        if ($column instanceof Closure || (is_callable($column) && !is_string($column))) {
            return $this->whereNested($column, $boolean);
        }

        // Assume '=' only when the operator slot is really the value (2-arg
        // shorthand). The shift is gated purely on the argument count — never
        // on $value being null — so an explicit where('col', '!=', null) keeps
        // its operator instead of collapsing to 'col = "!="'.
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        if ($value instanceof Closure || $value instanceof QueryBuilder) {
            return $this->whereSub($column, $operator ?? '=', $value, $boolean);
        }

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

        // A boolean against a JSON path is written into the SQL rather than
        // bound. PDO sends a bound true as the integer 1, which never equals
        // the JSON literal the column holds, so the row is silently missed.
        if (is_bool($value) && str_contains($column, '->')) {
            $this->wheres[] = [
                'type' => 'json_boolean',
                'column' => $column,
                'operator' => $operator,
                'value' => $value ? 'true' : 'false',
                'boolean' => $boolean,
            ];

            return $this;
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
     * Add several where clauses at once.
     *
     * Two shapes are accepted: ['column' => value] pairs, and a list of
     * [column, operator, value] triples. A list of them is wrapped in its own
     * group so an OR outside cannot split the set apart.
     *
     * @param array<mixed> $conditions
     */
    protected function addArrayOfWheres(array $conditions, string $boolean, string $method = 'where'): static
    {
        return $this->whereNested(function (QueryBuilder $query) use ($conditions, $method): void {
            foreach ($conditions as $key => $condition) {
                if (is_int($key) && is_array($condition)) {
                    $query->{$method}(...array_values($condition));

                    continue;
                }

                $query->{$method}($key, '=', $condition);
            }
        }, $boolean);
    }

    /**
     * Resolve the (value, operator) pair for a where clause. When only the
     * column and one more argument are supplied, that argument is the value and
     * the operator defaults to '='. Otherwise the pair is returned untouched.
     * Callers that build their own boolean (orWhere, …) prepare before
     * delegating, so where() never re-guesses on their behalf.
     */
    public function prepareValueAndOperator(mixed $value, mixed $operator, bool $useDefault = false): array
    {
        return $useDefault ? [$operator, '='] : [$value, $operator];
    }

    public function orWhere(string|array|Closure|callable $column, mixed $operator = null, mixed $value = null): static
    {
        // Prepare here (on our own arg count) so where() — which always receives
        // four arguments from us — never re-guesses the operator/value split.
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->where($column, $operator, $value, 'OR');
    }

    /** Negate a condition, or a whole group of them. */
    public function whereNot(string|array|Closure|callable $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        // A callback's conditions go straight into the negated group. Passing
        // them through where() would nest a group inside a group and negate
        // the outer one, which reads the same but says it twice.
        $callback = ($column instanceof Closure || (is_callable($column) && !is_string($column)))
            ? $column
            : static fn (QueryBuilder $query) => $query->where($column, $operator, $value);

        return $this->whereNested($callback, $boolean, true);
    }

    public function orWhereNot(string|array|Closure|callable $column, mixed $operator = null, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereNot($column, $operator, $value, 'OR');
    }

    // ─── Nesting ──────────────────────────────────────────

    /**
     * Group the conditions a callback adds into a single parenthesised clause.
     *
     * The group is stored as structure rather than pre-rendered SQL, so the
     * grammar still sees every clause inside it — which is what lets a driver
     * compile a nested LIKE or date part its own way.
     */
    public function whereNested(Closure|callable $callback, string $boolean = 'AND', bool $not = false): static
    {
        $query = $this->forNestedWhere();

        $callback($query);

        return $this->addNestedWhereQuery($query, $boolean, $not);
    }

    /** A builder for a nested group, carrying this query's table. */
    public function forNestedWhere(): static
    {
        return $this->newQuery()->from($this->from);
    }

    /** Fold a nested group's clauses and bindings into this query. */
    public function addNestedWhereQuery(QueryBuilder $query, string $boolean = 'AND', bool $not = false): static
    {
        $wheres = $query->getWheres();

        if ($wheres === []) {
            return $this;
        }

        $this->wheres[] = [
            'type' => $not ? 'not_nested' : 'nested',
            'wheres' => $wheres,
            'boolean' => $boolean,
        ];

        $this->bindings['where'] = array_merge(
            $this->bindings['where'],
            $query->getRawBindings()['where'] ?? []
        );

        return $this;
    }

    /**
     * Append another query's where clauses and their bindings.
     *
     * @param array<int, array<string, mixed>> $wheres
     * @param array<int, mixed>                $bindings
     */
    public function mergeWheres(array $wheres, array $bindings = []): static
    {
        $this->wheres = array_merge($this->wheres, $wheres);
        $this->bindings['where'] = array_merge($this->bindings['where'], array_values($bindings));

        return $this;
    }

    // ─── Sub-selects ──────────────────────────────────────

    /** Compare a column against the single value a sub-select returns. */
    protected function whereSub(string $column, string $operator, Closure|QueryBuilder $callback, string $boolean): static
    {
        $query = $this->resolveSubQuery($callback);

        $this->wheres[] = [
            'type' => 'sub',
            'column' => $column,
            'operator' => $operator,
            'query' => $query->toSql(),
            'boolean' => $boolean,
        ];

        $this->bindings['where'] = array_merge($this->bindings['where'], $query->getBindings());

        return $this;
    }

    /** Run a callback against a fresh builder, or accept one already built. */
    protected function resolveSubQuery(Closure|callable|QueryBuilder $callback): QueryBuilder
    {
        if ($callback instanceof QueryBuilder) {
            return $callback;
        }

        $query = $this->newQuery();

        $callback($query);

        return $query;
    }

    // ─── IN / NOT IN ──────────────────────────────────────

    public function whereIn(string $column, array|Closure|QueryBuilder $values, string $boolean = 'AND'): static
    {
        if (! is_array($values)) {
            return $this->whereInSub($column, $values, $boolean, false);
        }

        // An empty set matches nothing. Compiling "IN ()" is a syntax error, so
        // the impossible condition is stated directly instead.
        if ($values === []) {
            return $this->whereRaw('0 = 1', [], $boolean);
        }

        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => array_values($values),
            'boolean' => $boolean,
        ];
        $this->bindings['where'] = array_merge($this->bindings['where'], array_values($values));

        return $this;
    }

    public function whereNotIn(string $column, array|Closure|QueryBuilder $values, string $boolean = 'AND'): static
    {
        if (! is_array($values)) {
            return $this->whereInSub($column, $values, $boolean, true);
        }

        // Excluding nothing excludes nothing: every row still qualifies.
        if ($values === []) {
            return $this->whereRaw('1 = 1', [], $boolean);
        }

        $this->wheres[] = [
            'type' => 'not_in',
            'column' => $column,
            'values' => array_values($values),
            'boolean' => $boolean,
        ];
        $this->bindings['where'] = array_merge($this->bindings['where'], array_values($values));

        return $this;
    }

    protected function whereInSub(string $column, Closure|QueryBuilder $callback, string $boolean, bool $not): static
    {
        $query = $this->resolveSubQuery($callback);

        $this->wheres[] = [
            'type' => $not ? 'not_in_sub' : 'in_sub',
            'column' => $column,
            'query' => $query->toSql(),
            'boolean' => $boolean,
        ];

        $this->bindings['where'] = array_merge($this->bindings['where'], $query->getBindings());

        return $this;
    }

    /**
     * An IN clause over integers written straight into the SQL.
     *
     * For very large id sets, where a placeholder per value would push the
     * statement past the driver's parameter limit. Every value is cast to int,
     * so nothing reaches the SQL that is not a number.
     *
     * @param array<int, mixed> $values
     */
    public function whereIntegerInRaw(string $column, array $values, string $boolean = 'AND', bool $not = false): static
    {
        if ($values === []) {
            return $this->whereRaw($not ? '1 = 1' : '0 = 1', [], $boolean);
        }

        $this->wheres[] = [
            'type' => $not ? 'not_in_raw' : 'in_raw',
            'column' => $column,
            'values' => array_map(static fn ($value): int => (int) $value, array_values($values)),
            'boolean' => $boolean,
        ];

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereIntegerNotInRaw(string $column, array $values, string $boolean = 'AND'): static
    {
        return $this->whereIntegerInRaw($column, $values, $boolean, true);
    }

    // ─── NULL ─────────────────────────────────────────────

    /** @param string|array<int, string> $columns */
    public function whereNull(string|array $columns, string $boolean = 'AND', bool $not = false): static
    {
        foreach ((array) $columns as $column) {
            $this->wheres[] = [
                'type' => $not ? 'not_null' : 'null',
                'column' => $column,
                'boolean' => $boolean,
            ];
        }

        return $this;
    }

    /** @param string|array<int, string> $columns */
    public function whereNotNull(string|array $columns, string $boolean = 'AND'): static
    {
        return $this->whereNull($columns, $boolean, true);
    }

    // ─── BETWEEN ──────────────────────────────────────────

    /** @param array<int, mixed> $values */
    public function whereBetween(string $column, array $values, string $boolean = 'AND', bool $not = false): static
    {
        $values = array_values($values);

        if (count($values) !== 2) {
            throw new InvalidArgumentException('whereBetween() expects exactly two values.');
        }

        $this->wheres[] = [
            'type' => $not ? 'not_between' : 'between',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];

        $this->bindings['where'][] = $values[0];
        $this->bindings['where'][] = $values[1];

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereNotBetween(string $column, array $values, string $boolean = 'AND'): static
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Test a column against the range two other columns describe.
     *
     * @param array<int, string> $columns
     */
    public function whereBetweenColumns(string $column, array $columns, string $boolean = 'AND', bool $not = false): static
    {
        $columns = array_values($columns);

        if (count($columns) !== 2) {
            throw new InvalidArgumentException('whereBetweenColumns() expects exactly two columns.');
        }

        $this->wheres[] = [
            'type' => $not ? 'not_between_columns' : 'between_columns',
            'column' => $column,
            'values' => $columns,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /** @param array<int, string> $columns */
    public function whereNotBetweenColumns(string $column, array $columns, string $boolean = 'AND'): static
    {
        return $this->whereBetweenColumns($column, $columns, $boolean, true);
    }

    // ─── Column comparison ────────────────────────────────

    /** @param string|array<mixed> $first */
    public function whereColumn(string|array $first, ?string $operator = null, ?string $second = null, string $boolean = 'AND'): static
    {
        if (is_array($first)) {
            return $this->addArrayOfWheres($first, $boolean, 'whereColumn');
        }

        [$second, $operator] = $this->prepareValueAndOperator($second, $operator, func_num_args() === 2);

        $this->wheres[] = [
            'type' => 'column',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /** @param string|array<mixed> $first */
    public function orWhereColumn(string|array $first, ?string $operator = null, ?string $second = null): static
    {
        [$second, $operator] = $this->prepareValueAndOperator($second, $operator, func_num_args() === 2);

        return $this->whereColumn($first, $operator, $second, 'OR');
    }

    /**
     * Compare a tuple of columns against a tuple of values.
     *
     * @param array<int, string> $columns
     * @param array<int, mixed>  $values
     */
    public function whereRowValues(array $columns, string $operator, array $values, string $boolean = 'AND'): static
    {
        if (count($columns) !== count($values)) {
            throw new InvalidArgumentException('whereRowValues() expects the same number of columns and values.');
        }

        $this->wheres[] = [
            'type' => 'row_values',
            'columns' => array_values($columns),
            'operator' => $operator,
            'values' => array_values($values),
            'boolean' => $boolean,
        ];

        $this->bindings['where'] = array_merge($this->bindings['where'], array_values($values));

        return $this;
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, mixed>  $values
     */
    public function orWhereRowValues(array $columns, string $operator, array $values): static
    {
        return $this->whereRowValues($columns, $operator, $values, 'OR');
    }

    // ─── EXISTS ───────────────────────────────────────────

    public function whereExists(Closure|QueryBuilder $callback, string $boolean = 'AND', bool $not = false): static
    {
        return $this->addWhereExistsQuery($this->resolveSubQuery($callback), $boolean, $not);
    }

    public function addWhereExistsQuery(QueryBuilder $query, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => $not ? 'not_exists' : 'exists',
            'query' => $query->toSql(),
            'boolean' => $boolean,
        ];

        $this->bindings['where'] = array_merge($this->bindings['where'], $query->getBindings());

        return $this;
    }

    public function whereNotExists(Closure|QueryBuilder $callback, string $boolean = 'AND'): static
    {
        return $this->whereExists($callback, $boolean, true);
    }

    public function orWhereExists(Closure|QueryBuilder $callback): static
    {
        return $this->whereExists($callback, 'OR');
    }

    public function orWhereNotExists(Closure|QueryBuilder $callback): static
    {
        return $this->whereExists($callback, 'OR', true);
    }

    // ─── Raw ──────────────────────────────────────────────

    /** @param array<int, mixed> $bindings */
    public function whereRaw(string $expression, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'raw',
            'expression' => new RawExpression($expression),
            'boolean' => $boolean,
        ];
        $this->bindings['where'] = array_merge($this->bindings['where'], array_values($bindings));

        return $this;
    }

    /** @param array<int, mixed> $bindings */
    public function orWhereRaw(string $expression, array $bindings = []): static
    {
        return $this->whereRaw($expression, $bindings, 'OR');
    }

    // ─── Dates ────────────────────────────────────────────

    /**
     * Compare one part of a datetime column.
     *
     * The expression around the column comes from the grammar, because the
     * functions differ by engine; the operator is validated there too, so a
     * value arriving in the operator slot cannot reach the SQL.
     */
    protected function addDateBasedWhere(string $part, string $column, mixed $operator, mixed $value, string $boolean, bool $twoArguments): static
    {
        if ($twoArguments) {
            [$value, $operator] = [$operator, '='];
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(match ($part) {
                'date' => 'Y-m-d',
                'year' => 'Y',
                'month' => 'm',
                'day' => 'd',
                'time' => 'H:i:s',
            });
        }

        // Zero-pad, because the engines that read these parts out of a string
        // return '03' for March and would never match a bound 3.
        if (($part === 'month' || $part === 'day') && is_numeric($value)) {
            $value = sprintf('%02d', $value);
        }

        $this->wheres[] = [
            'type' => 'date',
            'part' => $part,
            'column' => $column,
            'operator' => $operator,
            'boolean' => $boolean,
        ];

        $this->bindings['where'][] = $value;

        return $this;
    }

    public function whereDate(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND'): static
    {
        return $this->addDateBasedWhere('date', $column, $operator, $value, $boolean, func_num_args() === 2);
    }

    public function whereTime(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND'): static
    {
        return $this->addDateBasedWhere('time', $column, $operator, $value, $boolean, func_num_args() === 2);
    }

    public function whereDay(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND'): static
    {
        return $this->addDateBasedWhere('day', $column, $operator, $value, $boolean, func_num_args() === 2);
    }

    public function whereMonth(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND'): static
    {
        return $this->addDateBasedWhere('month', $column, $operator, $value, $boolean, func_num_args() === 2);
    }

    public function whereYear(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND'): static
    {
        return $this->addDateBasedWhere('year', $column, $operator, $value, $boolean, func_num_args() === 2);
    }

    public function orWhereDate(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDateBasedWhere('date', $column, $operator, $value, 'OR', func_num_args() === 2);
    }

    public function orWhereTime(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDateBasedWhere('time', $column, $operator, $value, 'OR', func_num_args() === 2);
    }

    public function orWhereDay(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDateBasedWhere('day', $column, $operator, $value, 'OR', func_num_args() === 2);
    }

    public function orWhereMonth(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDateBasedWhere('month', $column, $operator, $value, 'OR', func_num_args() === 2);
    }

    public function orWhereYear(string $column, mixed $operator, mixed $value = null): static
    {
        return $this->addDateBasedWhere('year', $column, $operator, $value, 'OR', func_num_args() === 2);
    }

    // ─── LIKE ─────────────────────────────────────────────

    /**
     * Match a column against a pattern.
     *
     * Case sensitivity is asked for explicitly rather than inherited from the
     * column's collation, so the same call means the same thing on every
     * engine — or fails loudly on one that cannot honour it.
     */
    public function whereLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => 'like',
            'column' => $column,
            'value' => $value,
            'caseSensitive' => $caseSensitive,
            'not' => $not,
            'boolean' => $boolean,
        ];

        $this->bindings['where'][] = $this->grammar->prepareLikeBinding($value, $caseSensitive);

        return $this;
    }

    public function whereNotLike(string $column, string $value, bool $caseSensitive = false, string $boolean = 'AND'): static
    {
        return $this->whereLike($column, $value, $caseSensitive, $boolean, true);
    }

    public function orWhereLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereLike($column, $value, $caseSensitive, 'OR');
    }

    public function orWhereNotLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereLike($column, $value, $caseSensitive, 'OR', true);
    }

    // ─── Column sets ──────────────────────────────────────

    /**
     * Apply one condition to every column in a set, all of which must hold.
     *
     * @param array<int, string> $columns
     */
    public function whereAll(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereNested(function (QueryBuilder $query) use ($columns, $operator, $value): void {
            foreach ($columns as $column) {
                $query->where($column, $operator, $value, 'AND');
            }
        }, $boolean);
    }

    /**
     * Apply one condition to every column in a set, any of which may hold.
     *
     * @param array<int, string> $columns
     */
    public function whereAny(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereNested(function (QueryBuilder $query) use ($columns, $operator, $value): void {
            foreach ($columns as $column) {
                $query->where($column, $operator, $value, 'OR');
            }
        }, $boolean);
    }

    /**
     * Apply one condition to every column in a set, none of which may hold.
     *
     * @param array<int, string> $columns
     */
    public function whereNone(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereNested(function (QueryBuilder $query) use ($columns, $operator, $value): void {
            foreach ($columns as $column) {
                $query->where($column, $operator, $value, 'OR');
            }
        }, $boolean, true);
    }

    /** @param array<int, string> $columns */
    public function orWhereAll(array $columns, mixed $operator = null, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereAll($columns, $operator, $value, 'OR');
    }

    /** @param array<int, string> $columns */
    public function orWhereAny(array $columns, mixed $operator = null, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereAny($columns, $operator, $value, 'OR');
    }

    /** @param array<int, string> $columns */
    public function orWhereNone(array $columns, mixed $operator = null, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->whereNone($columns, $operator, $value, 'OR');
    }

    // ─── Dynamic where ────────────────────────────────────

    /**
     * Turn a method name into where clauses: whereNameAndEmail('a', 'b').
     *
     * The segments are read off the name in order, each taking the next
     * argument, joined by the And/Or that separated them.
     *
     * @param array<int, mixed> $parameters
     */
    public function dynamicWhere(string $method, array $parameters): static
    {
        $finder = substr($method, 5);

        $segments = preg_split(
            '/(And|Or)(?=[A-Z])/',
            $finder,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $boolean = 'AND';
        $index = 0;

        foreach ($segments as $segment) {
            if ($segment === 'And' || $segment === 'Or') {
                $boolean = strtoupper($segment);

                continue;
            }

            if (! array_key_exists($index, $parameters)) {
                throw new InvalidArgumentException(
                    "Too few arguments for [{$method}]: [" . $this->snakeSegment($segment) . '] has no value.'
                );
            }

            $this->where($this->snakeSegment($segment), '=', $parameters[$index], $boolean);

            $index++;
        }

        return $this;
    }

    private function snakeSegment(string $segment): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $segment));
    }

    // ─── Or variants ──────────────────────────────────────

    public function orWhereIn(string $column, array|Closure|QueryBuilder $values): static
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function orWhereNotIn(string $column, array|Closure|QueryBuilder $values): static
    {
        return $this->whereNotIn($column, $values, 'OR');
    }

    /** @param array<int, mixed> $values */
    public function orWhereIntegerInRaw(string $column, array $values): static
    {
        return $this->whereIntegerInRaw($column, $values, 'OR');
    }

    /** @param array<int, mixed> $values */
    public function orWhereIntegerNotInRaw(string $column, array $values): static
    {
        return $this->whereIntegerInRaw($column, $values, 'OR', true);
    }

    /** @param string|array<int, string> $columns */
    public function orWhereNull(string|array $columns): static
    {
        return $this->whereNull($columns, 'OR');
    }

    /** @param string|array<int, string> $columns */
    public function orWhereNotNull(string|array $columns): static
    {
        return $this->whereNotNull($columns, 'OR');
    }

    /** @param array<int, mixed> $values */
    public function orWhereBetween(string $column, array $values): static
    {
        return $this->whereBetween($column, $values, 'OR');
    }

    /** @param array<int, mixed> $values */
    public function orWhereNotBetween(string $column, array $values): static
    {
        return $this->whereBetween($column, $values, 'OR', true);
    }

    /** @param array<int, string> $columns */
    public function orWhereBetweenColumns(string $column, array $columns): static
    {
        return $this->whereBetweenColumns($column, $columns, 'OR');
    }

    /** @param array<int, string> $columns */
    public function orWhereNotBetweenColumns(string $column, array $columns): static
    {
        return $this->whereBetweenColumns($column, $columns, 'OR', true);
    }
}
