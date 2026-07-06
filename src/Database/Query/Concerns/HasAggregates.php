<?php

namespace Nitro\Database\Query\Concerns;

/**
 * Query builder concern: aggregate helpers (count/sum/avg/min/max).
 */
trait HasAggregates
{
    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function sum(string $column): float
    {
        return (float) $this->aggregate('SUM', $column);
    }

    public function avg(string $column): float
    {
        return (float) $this->aggregate('AVG', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    protected function aggregate(string $function, string $column): mixed
    {
        $sql = $this->grammar->compileAggregate($this, $function, $column);
        $result = $this->connection->selectOne($sql, $this->getBindings());
        return $result ? $result->aggregate : null;
    }
}
