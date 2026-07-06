<?php
/**
 * Collection Helper Functions
 * 
 * Provides utility functions for working with collections.
 * 
 * @package Nitro\Support
 * @author Zeeshan Ali 
 * @version 1.0
 */



if (!function_exists('collect')) {
    /**
     * Create a Collection from the given items.
     */
    function collect(iterable $items = []): \Nitro\Support\Collection
    {
        return new \Nitro\Support\Collection(
            is_array($items) ? $items : iterator_to_array($items)
        );
    }
}

if (!function_exists('transform')) {
    /**
     * Transform a value if it's not null
     * 
     * @param mixed $value
     * @param callable $callback
     * @param mixed $default
     * @return mixed
     */
    function transform($value, callable $callback, $default = null)
    {
        if ($value !== null) {
            return $callback($value);
        }

        return $default;
    }
}

if (!function_exists('with')) {
    /**
     * Return the given value, optionally passed through a callback
     * 
     * @param mixed $value
     * @param callable|null $callback
     * @return mixed
     */
    function with($value, ?callable $callback = null)
    {
        return $callback ? $callback($value) : $value;
    }
}

if (!function_exists('tap')) {
    /**
     * Call the given callback with the given value, then return the value
     * 
     * @param mixed $value
     * @param callable $callback
     * @return mixed
     */
    function tap($value, callable $callback)
    {
        $callback($value);
        return $value;
    }
}