<?php

use App\Services\QueryRegistry;

if (!function_exists('query')) {
    /**
     * Resolve a registered query by name, or return the registry
     *
     * @param string|null $name
     * @param array $params
     * @return QueryRegistry|\Nitro\Database\Query\QueryBuilder
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