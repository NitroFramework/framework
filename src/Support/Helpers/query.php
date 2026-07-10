<?php

use Nitro\Database\Query\QueryRegistry;

if (!function_exists('query')) {
    /**
     * The named-query registry, or a named query resolved to a live builder.
     *
     *   query()                             // the registry (->register(), ->has(), ->names())
     *   query('students.honor_roll')        // resolve → a builder you can chain
     *   query('students.in', ['Lahore'])    // resolve with parameters
     *
     * @return QueryRegistry|\Nitro\Database\Query\QueryBuilder|mixed
     */
    function query(?string $name = null, array $params = [])
    {
        $registry = app(QueryRegistry::class);

        if ($name === null) {
            return $registry;
        }

        return $registry->resolve($name, $params);
    }
}
