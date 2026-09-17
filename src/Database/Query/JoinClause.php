<?php

namespace Nitro\Database\Query;

use Closure;

/**
 * The conditions attached to one JOIN.
 *
 * A join's ON is a boolean expression like any other, so this is a query
 * builder in its own right: on() and orOn() compare two columns, and every
 * where method is available for comparing a column against a value.
 *
 *     $query->join('posts', function (JoinClause $join) {
 *         $join->on('posts.user_id', '=', 'users.id')
 *              ->where('posts.published', true);
 *     });
 */
class JoinClause extends QueryBuilder
{
    public function __construct(
        QueryBuilder $parent,
        public readonly string $joinType,
        public readonly string|RawExpression $joinTable,
    ) {
        parent::__construct($parent->getConnection(), $parent->getGrammar());
    }

    /**
     * Compare two columns.
     *
     * A closure groups the conditions it adds, so an OR inside cannot escape
     * the group and change what the rest of the ON means.
     */
    public function on(string|Closure $first, ?string $operator = null, ?string $second = null, string $boolean = 'AND'): static
    {
        if ($first instanceof Closure) {
            return $this->whereNested($first, $boolean);
        }

        return $this->whereColumn($first, $operator, $second, $boolean);
    }

    public function orOn(string|Closure $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->on($first, $operator, $second, 'OR');
    }

    /**
     * A clause of the same kind, for the nested groups and sub-selects the
     * where methods build.
     */
    public function newQuery(): static
    {
        return new static($this, $this->joinType, $this->joinTable);
    }
}
