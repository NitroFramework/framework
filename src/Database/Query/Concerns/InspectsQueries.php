<?php

namespace Nitro\Database\Query\Concerns;

use InvalidArgumentException;
use Nitro\Database\Query\QueryBuilder;

/**
 * Query builder concern: bindings, cloning and seeing what a query will run.
 */
trait InspectsQueries
{
    /** Append bindings to one bucket, keeping compile order intact. */
    public function addBinding(mixed $value, string $type = 'where'): static
    {
        $this->assertBindingType($type);

        foreach (is_array($value) ? array_values($value) : [$value] as $binding) {
            $this->bindings[$type][] = $binding;
        }

        return $this;
    }

    /**
     * Replace one bucket's bindings.
     *
     * @param array<int, mixed> $bindings
     */
    public function setBindings(array $bindings, string $type = 'where'): static
    {
        $this->assertBindingType($type);

        $this->bindings[$type] = array_values($bindings);

        return $this;
    }

    /** Take on another query's bindings, bucket for bucket. */
    public function mergeBindings(QueryBuilder $query): static
    {
        foreach ($query->getRawBindings() as $type => $bindings) {
            if (isset($this->bindings[$type])) {
                $this->bindings[$type] = array_merge($this->bindings[$type], $bindings);
            }
        }

        return $this;
    }

    /**
     * Drop the values that are not bindings at all.
     *
     * A raw expression is written into the SQL, so it must not also be handed
     * to the driver as a parameter.
     *
     * @param  array<int, mixed> $bindings
     * @return array<int, mixed>
     */
    public function cleanBindings(array $bindings): array
    {
        return array_values(array_filter(
            $bindings,
            static fn ($binding): bool => ! $binding instanceof \Nitro\Database\Query\RawExpression
        ));
    }

    private function assertBindingType(string $type): void
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }
    }

    /**
     * A copy with some of its state cleared.
     *
     * @param array<int, string> $properties
     */
    public function cloneWithout(array $properties): static
    {
        $clone = clone $this;

        foreach ($properties as $property) {
            $clone->{$property} = match ($property) {
                'limitValue', 'offsetValue', 'lock', 'indexHint' => null,
                'columns' => ['*'],
                'distinct' => false,
                default => [],
            };
        }

        return $clone;
    }

    /**
     * A copy with some of its binding buckets emptied.
     *
     * @param array<int, string> $types
     */
    public function cloneWithoutBindings(array $types): static
    {
        $clone = clone $this;

        foreach ($types as $type) {
            $clone->assertBindingType($type);
            $clone->bindings[$type] = [];
        }

        return $clone;
    }

    /**
     * The query as it would run, with the bindings written in.
     *
     * For reading and for pasting into a console — never for execution. The
     * quoting here is good enough to read, not to rely on.
     */
    public function toRawSql(): string
    {
        $sql = $this->toSql();

        foreach ($this->getBindings() as $binding) {
            $sql = preg_replace('/\?/', $this->substituteBinding($binding), $sql, 1);
        }

        return $sql;
    }

    private function substituteBinding(mixed $binding): string
    {
        $value = match (true) {
            $binding === null => 'null',
            is_bool($binding) => $binding ? '1' : '0',
            is_int($binding) || is_float($binding) => (string) $binding,
            $binding instanceof \DateTimeInterface => "'" . $binding->format('Y-m-d H:i:s') . "'",
            default => "'" . str_replace("'", "''", (string) $binding) . "'",
        };

        // The result goes back through preg_replace as a replacement, where a
        // backslash or a $1 would otherwise be read as a reference.
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
    }

    /** Print the SQL and bindings, and keep going. */
    public function dump(): static
    {
        dump(['sql' => $this->toSql(), 'bindings' => $this->getBindings()]);

        return $this;
    }

    public function dumpRawSql(): static
    {
        dump($this->toRawSql());

        return $this;
    }

    /** Print the SQL and bindings, and stop. */
    public function dd(): never
    {
        $this->dump();

        exit(1);
    }

    public function ddRawSql(): never
    {
        $this->dumpRawSql();

        exit(1);
    }
}
