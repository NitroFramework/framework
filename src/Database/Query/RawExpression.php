<?php

namespace Nitro\Database\Query;

/**
 * A raw SQL expression that bypasses grammar escaping.
 */
class RawExpression
{
    public function __construct(
        public readonly string $expression,
        public readonly array $bindings = []
    ) {}

    public function __toString(): string
    {
        return $this->expression;
    }
}
