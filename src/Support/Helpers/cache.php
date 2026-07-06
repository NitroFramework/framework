<?php

if (! function_exists('cache')) {
    function cache(?string $key = null, mixed $default = null)
    {
        $cache = app('cache')->store();

        if ($key === null) {
            return $cache;
        }

        return $cache->get($key, $default);
    }
}