<?php

namespace Nitro\Database\Query\Concerns;

use Closure;
use InvalidArgumentException;
use Nitro\Database\Query\QueryBuilder;
use Nitro\Database\Query\RawExpression;

/**
 * Query builder concern: HAVING clause construction.
 */
trait BuildsHavings
{
    public function having(string|Closure|callable $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        if ($column instanceof Closure || (is_callable($column) && !is_string($column))) {
            return $this->havingNested($column, $boolean);
        }

        // Same operator/value split as where(): only treat the operator slot as
        // the value when just two args were given, so having('c','>',null) keeps
        // its operator instead of collapsing to 'c = ">"'.
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        $this->havings[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];
        $this->bindings['having'][] = $value;

        return $this;
    }

    public function orHaving(string|Closure|callable $column, mixed $operator = null, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        return $this->having($column, $operator, $value, 'OR');
    }

    /** Group the conditions a callback adds into one parenthesised clause. */
    public function havingNested(Closure|callable $callback, string $boolean = 'AND'): static
    {
        $query = $this->newQuery();

        $callback($query);

        return $this->addNestedHavingQuery($query, $boolean);
    }

    /** Fold a nested group's clauses and bindings into this query. */
    public function addNestedHavingQuery(QueryBuilder $query, string $boolean = 'AND'): static
    {
        $havings = $query->getHavings();

        if ($havings === []) {
            return $this;
        }

        $this->havings[] = [
            'type' => 'nested',
            'havings' => $havings,
            'boolean' => $boolean,
        ];

        $this->bindings['having'] = array_merge(
            $this->bindings['having'],
            $query->getRawBindings()['having'] ?? []
        );

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function havingBetween(string $column, array $values, string $boolean = 'AND', bool $not = false): static
    {
        $values = array_values($values);

        if (count($values) !== 2) {
            throw new InvalidArgumentException('havingBetween() expects exactly two values.');
        }

        $this->havings[] = [
            'type' => $not ? 'not_between' : 'between',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];

        $this->bindings['having'][] = $values[0];
        $this->bindings['having'][] = $values[1];

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function havingNotBetween(string $column, array $values, string $boolean = 'AND'): static
    {
        return $this->havingBetween($column, $values, $boolean, true);
    }

    /** @param array<int, mixed> $values */
    public function orHavingBetween(string $column, array $values): static
    {
        return $this->havingBetween($column, $values, 'OR');
    }

    /** @param array<int, mixed> $values */
    public function orHavingNotBetween(string $column, array $values): static
    {
        return $this->havingBetween($column, $values, 'OR', true);
    }

    /** @param string|array<int, string> $columns */
    public function havingNull(string|array $columns, string $boolean = 'AND', bool $not = false): static
    {
        foreach ((array) $columns as $column) {
            $this->havings[] = [
                'type' => $not ? 'not_null' : 'null',
                'column' => $column,
                'boolean' => $boolean,
            ];
        }

        return $this;
    }

    /** @param string|array<int, string> $columns */
    public function havingNotNull(string|array $columns, string $boolean = 'AND'): static
    {
        return $this->havingNull($columns, $boolean, true);
    }

    /** @param string|array<int, string> $columns */
    public function orHavingNull(string|array $columns): static
    {
        return $this->havingNull($columns, 'OR');
    }

    /** @param string|array<int, string> $columns */
    public function orHavingNotNull(string|array $columns): static
    {
        return $this->havingNull($columns, 'OR', true);
    }

    /** @param array<int, mixed> $bindings */
    public function havingRaw(string $expression, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->havings[] = [
            'type' => 'raw',
            'expression' => new RawExpression($expression),
            'boolean' => $boolean,
        ];

        $this->bindings['having'] = array_merge($this->bindings['having'], array_values($bindings));

        return $this;
    }

    /** @param array<int, mixed> $bindings */
    public function orHavingRaw(string $expression, array $bindings = []): static
    {
        return $this->havingRaw($expression, $bindings, 'OR');
    }
}
