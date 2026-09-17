<?php

namespace Nitro\Database\Query\Concerns;

/**
 * Query builder concern: conditions over JSON columns and full-text indexes.
 *
 * A column here may carry a path — 'options->notifications->email' — which the
 * grammar turns into the engine's own JSON accessor.
 */
trait BuildsJsonWheres
{
    /** Whether a JSON array or object contains the given value. */
    public function whereJsonContains(string $column, mixed $value, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => 'json_contains',
            'column' => $column,
            'not' => $not,
            'boolean' => $boolean,
        ];

        $this->bindings['where'][] = $this->grammar->prepareJsonContainsBinding($value);

        return $this;
    }

    public function whereJsonDoesntContain(string $column, mixed $value, string $boolean = 'AND'): static
    {
        return $this->whereJsonContains($column, $value, $boolean, true);
    }

    public function orWhereJsonContains(string $column, mixed $value): static
    {
        return $this->whereJsonContains($column, $value, 'OR');
    }

    public function orWhereJsonDoesntContain(string $column, mixed $value): static
    {
        return $this->whereJsonContains($column, $value, 'OR', true);
    }

    /** Whether the JSON document has anything at the given path. */
    public function whereJsonContainsKey(string $column, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => 'json_contains_key',
            'column' => $column,
            'not' => $not,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereJsonDoesntContainKey(string $column, string $boolean = 'AND'): static
    {
        return $this->whereJsonContainsKey($column, $boolean, true);
    }

    public function orWhereJsonContainsKey(string $column): static
    {
        return $this->whereJsonContainsKey($column, 'OR');
    }

    public function orWhereJsonDoesntContainKey(string $column): static
    {
        return $this->whereJsonContainsKey($column, 'OR', true);
    }

    /** Compare the number of elements in a JSON array. */
    public function whereJsonLength(string $column, mixed $operator, mixed $value = null, string $boolean = 'AND'): static
    {
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }

        $this->wheres[] = [
            'type' => 'json_length',
            'column' => $column,
            'operator' => $operator,
            'boolean' => $boolean,
        ];

        $this->bindings['where'][] = $value;

        return $this;
    }

    public function orWhereJsonLength(string $column, mixed $operator, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }

        return $this->whereJsonLength($column, $operator, $value, 'OR');
    }

    /** Whether two JSON arrays have any element in common. */
    public function whereJsonOverlaps(string $column, mixed $value, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => 'json_overlaps',
            'column' => $column,
            'not' => $not,
            'boolean' => $boolean,
        ];

        $this->bindings['where'][] = $this->grammar->prepareJsonOverlapsBinding($value);

        return $this;
    }

    public function whereJsonDoesntOverlap(string $column, mixed $value, string $boolean = 'AND'): static
    {
        return $this->whereJsonOverlaps($column, $value, $boolean, true);
    }

    public function orWhereJsonOverlaps(string $column, mixed $value): static
    {
        return $this->whereJsonOverlaps($column, $value, 'OR');
    }

    public function orWhereJsonDoesntOverlap(string $column, mixed $value): static
    {
        return $this->whereJsonOverlaps($column, $value, 'OR', true);
    }

    // ─── Full text ────────────────────────────────────────

    /**
     * Search a full-text index.
     *
     * @param string|array<int, string> $columns
     * @param array<string, mixed>      $options
     */
    public function whereFullText(string|array $columns, string $value, array $options = [], string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'fulltext',
            'columns' => (array) $columns,
            'options' => $options,
            'boolean' => $boolean,
        ];

        $this->bindings['where'][] = $value;

        return $this;
    }

    /**
     * @param string|array<int, string> $columns
     * @param array<string, mixed>      $options
     */
    public function orWhereFullText(string|array $columns, string $value, array $options = []): static
    {
        return $this->whereFullText($columns, $value, $options, 'OR');
    }
}
