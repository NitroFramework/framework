<?php

/**
 * Array Helper Functions
 * 
 * Provides utility functions for working with arrays.
 * 
 * @package Nitro\Support
 * @author Zeeshan Ali 
 * @version 1.0
 */


if (!function_exists('array_get')) {
    /**
     * Get an item from an array using dot notation
     * 
     * @param array $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function array_get(array $array, string $key, $default = null)
    {
        if (isset($array[$key])) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }
}

if (!function_exists('array_set')) {
    /**
     * Set an array item using dot notation
     * 
     * @param array $array
     * @param string $key
     * @param mixed $value
     * @return array
     */
    function array_set(array &$array, string $key, $value): array
    {
        $keys = explode('.', $key);

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }
}

if (!function_exists('array_has')) {
    /**
     * Check if array has key using dot notation
     * 
     * @param array $array
     * @param string $key
     * @return bool
     */
    function array_has(array $array, string $key): bool
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return false;
            }
            $array = $array[$segment];
        }

        return true;
    }
}

if (!function_exists('array_only')) {
    /**
     * Get only specified keys from array
     * 
     * @param array $array
     * @param array $keys
     * @return array
     */
    function array_only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }
}

if (!function_exists('array_except')) {
    /**
     * Get array without specified keys
     * 
     * @param array $array
     * @param array $keys
     * @return array
     */
    function array_except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }
}