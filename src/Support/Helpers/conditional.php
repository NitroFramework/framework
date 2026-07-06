<?php

/**
 * Conditional Helper Functions
 * 
 * Provides utility functions for conditional operations.
 * 
 * @package Nitro\Support
 * @author Zeeshan Ali 
 * @version 1.0
 */


if (!function_exists('when')) {
    /**
     * Execute callback when condition is true
     * 
     * @param bool $condition
     * @param callable $callback
     * @param callable|null $default
     * @return mixed
     */
    function when(bool $condition, callable $callback, ?callable $default = null)
    {
        if ($condition) {
            return $callback();
        }

        return $default ? $default() : null;
    }
}

if (!function_exists('unless')) {
    /**
     * Execute callback when condition is false
     * 
     * @param bool $condition
     * @param callable $callback
     * @param callable|null $default
     * @return mixed
     */
    function unless(bool $condition, callable $callback, ?callable $default = null)
    {
        return when(!$condition, $callback, $default);
    }
}

if (!function_exists('optional')) {
    /**
     * Provide access to optional objects
     * 
     * @param mixed $value
     * @param callable|null $callback
     * @return mixed
     */
    function optional($value = null, ?callable $callback = null)
    {
        if ($value === null) {
            return new class {
                public function __call($method, $args)
                {
                    return null;
                }
                public function __get($key)
                {
                    return null;
                }
                public function __set($key, $value)
                {
                    return null;
                }
                public function __isset($key)
                {
                    return false;
                }
                public function __unset($key)
                {
                    return null;
                }
            };
        }

        return $callback ? $callback($value) : $value;
    }
}

