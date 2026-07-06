<?php

namespace Nitro\Support;

use Closure;

/**
 * Array helpers — Laravel's `Arr::` surface (common subset). get/set/has/forget
 * support "dot" notation.
 *
 *   Arr::get($config, 'mail.from.address', 'default');
 *   Arr::only($input, ['name', 'email']);
 */
class Arr
{
    public static function exists(array $array, string|int $key): bool
    {
        return array_key_exists($key, $array);
    }

    public static function get(array $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }

        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        if (!is_string($key) || !str_contains($key, '.')) {
            return $default;
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

    public static function has(array $array, string|int $key): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        }

        if (!is_string($key) || !str_contains($key, '.')) {
            return false;
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return false;
            }
        }

        return true;
    }

    public static function set(array &$array, string $key, mixed $value): array
    {
        $segments = explode('.', $key);
        $ref =& $array;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                break;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref =& $ref[$segment];
        }

        return $array;
    }

    public static function forget(array &$array, string $key): void
    {
        $segments = explode('.', $key);
        $ref =& $array;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                unset($ref[$segment]);
                return;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                return;
            }
            $ref =& $ref[$segment];
        }
    }

    public static function only(array $array, array|string $keys): array
    {
        return array_intersect_key($array, array_flip((array) $keys));
    }

    public static function except(array $array, array|string $keys): array
    {
        return array_diff_key($array, array_flip((array) $keys));
    }

    public static function first(array $array, ?Closure $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $array === [] ? $default : reset($array);
        }
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }
        return $default;
    }

    public static function last(array $array, ?Closure $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $array === [] ? $default : end($array);
        }
        return static::first(array_reverse($array, true), $callback, $default);
    }

    public static function pluck(array $array, string $value, ?string $key = null): array
    {
        $results = [];
        foreach ($array as $item) {
            $itemValue = is_array($item) ? ($item[$value] ?? null) : ($item->{$value} ?? null);
            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
                $results[$itemKey] = $itemValue;
            }
        }
        return $results;
    }

    public static function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        return is_array($value) ? $value : [$value];
    }

    public static function flatten(array $array, int $depth = PHP_INT_MAX): array
    {
        $result = [];
        foreach ($array as $item) {
            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge($result, static::flatten($item, $depth - 1));
            }
        }
        return $result;
    }

    public static function isAssoc(array $array): bool
    {
        return !array_is_list($array);
    }

    /**
     * Build a space-separated class string from a conditional class array:
     * numeric keys are always included; string keys are included when truthy.
     * Backs the `@class` directive and `<x-component>` :class merging.
     */
    public static function toCssClasses(array $classes): string
    {
        $result = [];

        foreach ($classes as $key => $value) {
            if (is_numeric($key)) {
                $result[] = $value;
            } elseif ($value) {
                $result[] = $key;
            }
        }

        return implode(' ', $result);
    }

    /**
     * Build a ";"-separated style string from a conditional style array:
     * numeric keys are always included; string keys are included when truthy.
     * Backs the `@style` directive and `<x-component>` :style merging.
     */
    public static function toCssStyles(array $styles): string
    {
        $result = [];

        foreach ($styles as $key => $value) {
            if (is_numeric($key)) {
                $result[] = $value;
            } elseif ($value) {
                $result[] = $key;
            }
        }

        return implode('; ', $result) . (empty($result) ? '' : ';');
    }
}
