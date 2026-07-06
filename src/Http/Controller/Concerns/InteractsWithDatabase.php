<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Database\DB;
use Nitro\Database\Query\QueryBuilder;

/**
 * Controller concern: convenience access to the database query builder.
 */
trait InteractsWithDatabase
{
    protected function db(): DB
    {
        // Return the DB facade class — but it's static...
        // So we return a proxy that forwards instance calls to static
        return new class extends DB {};
    }

    protected function table(string $table): QueryBuilder
    {
        return DB::table($table);
    }

    protected function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
