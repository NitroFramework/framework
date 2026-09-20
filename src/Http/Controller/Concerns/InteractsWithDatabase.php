<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Database\DB;
use Nitro\Database\Query\QueryBuilder;

/**
 * The query builder, as methods on the controller.
 *
 * The `DB` facade does the same from anywhere; this is for a controller that
 * reads better saying `$this->table(…)`.
 */
trait InteractsWithDatabase
{
    /**
     * An instance to call the facade's methods on.
     */
    protected function db(): DB
    {
        return new class extends DB {};
    }

    /**
     * Start a query against a table.
     */
    protected function table(string $table): QueryBuilder
    {
        return DB::table($table);
    }

    /**
     * Run the callback inside a transaction, rolling back if it throws.
     */
    protected function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
