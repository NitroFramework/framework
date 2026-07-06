<?php

namespace Nitro\Database\Query\Concerns;

/**
 * Query builder concern: JOIN clause construction.
 */
trait BuildsJoins
{
    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = ['type' => 'inner', 'table' => $table, 'first' => $first, 'operator' => $operator, 'second' => $second];
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = ['type' => 'left', 'table' => $table, 'first' => $first, 'operator' => $operator, 'second' => $second];
        return $this;
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = ['type' => 'right', 'table' => $table, 'first' => $first, 'operator' => $operator, 'second' => $second];
        return $this;
    }

    public function crossJoin(string $table): static
    {
        $this->joins[] = ['type' => 'cross', 'table' => $table, 'first' => '', 'operator' => '', 'second' => ''];
        return $this;
    }
}
