<?php

namespace Nitro\Database\Events;

/**
 * Payload for query.executing and query.executed.
 *
 * One class for both, because a listener that wants the SQL wants the same
 * SQL either way — only the elapsed time distinguishes them, and it is null
 * on the way in because the query has not run yet.
 */
class QueryEvent
{
    /**
     * @param string             $sql      The statement, with placeholders unresolved.
     * @param array<int, mixed>  $bindings Values bound to those placeholders.
     * @param float|null         $time     Milliseconds the query took, or null on query.executing.
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = [],
        public readonly ?float $time = null,
    ) {}
}
