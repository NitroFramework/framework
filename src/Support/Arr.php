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

    // ─── Shape ────────────────────────────────────────────────────────────

    /** Whether the value can be read with array syntax. */
    public static function accessible(mixed $value): bool
    {
        return is_array($value) || $value instanceof \ArrayAccess;
    }

    /** Whether the array is keyed 0..n-1 with no gaps. */
    public static function isList(array $array): bool
    {
        return array_is_list($array);
    }

    /** Whether the array has string keys. */
    public static function isAssocArray(array $array): bool
    {
        return ! array_is_list($array);
    }

    /**
     * Coerce anything array-like into an array.
     */
    public static function from(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return (array) $value;
    }

    /** Whether the value exposes a toArray() method. */
    public static function arrayable(mixed $value): bool
    {
        return is_object($value) && method_exists($value, 'toArray');
    }

    // ─── Reading ──────────────────────────────────────────────────────────

    /** Whether every one of the dot paths is present. */
    public static function hasAll(array $array, array|string $keys): bool
    {
        foreach ((array) $keys as $key) {
            if (! static::has($array, (string) $key)) {
                return false;
            }
        }

        return true;
    }

    /** Whether any of the dot paths is present. */
    public static function hasAny(array $array, array|string $keys): bool
    {
        foreach ((array) $keys as $key) {
            if (static::has($array, (string) $key)) {
                return true;
            }
        }

        return false;
    }

    /** Read a value and remove it from the array. */
    public static function pull(array &$array, string $key, mixed $default = null): mixed
    {
        $value = static::get($array, $key, $default);

        static::forget($array, $key);

        return $value;
    }

    /**
     * The one matching item, or a failure when there is not exactly one.
     *
     * @throws \RuntimeException When no item matches, or more than one does.
     */
    public static function sole(array $array, ?Closure $callback = null): mixed
    {
        $matches = $callback === null ? $array : array_filter($array, $callback);

        if ($matches === []) {
            throw new \RuntimeException('No matching item found.');
        }

        if (count($matches) > 1) {
            throw new \RuntimeException('More than one matching item found.');
        }

        return reset($matches);
    }

    // ─── Typed reads ──────────────────────────────────────────────────────

    /** @throws \UnexpectedValueException When the value is not a string. */
    public static function string(array $array, string $key, ?string $default = null): string
    {
        $value = static::get($array, $key, $default);

        if (! is_string($value)) {
            throw new \UnexpectedValueException("Array value for key [{$key}] is not a string.");
        }

        return $value;
    }

    /** @throws \UnexpectedValueException When the value is not an integer. */
    public static function integer(array $array, string $key, ?int $default = null): int
    {
        $value = static::get($array, $key, $default);

        if (! is_int($value)) {
            throw new \UnexpectedValueException("Array value for key [{$key}] is not an integer.");
        }

        return $value;
    }

    /** @throws \UnexpectedValueException When the value is not a float. */
    public static function float(array $array, string $key, ?float $default = null): float
    {
        $value = static::get($array, $key, $default);

        if (! is_float($value) && ! is_int($value)) {
            throw new \UnexpectedValueException("Array value for key [{$key}] is not a float.");
        }

        return (float) $value;
    }

    /** @throws \UnexpectedValueException When the value is not a boolean. */
    public static function boolean(array $array, string $key, ?bool $default = null): bool
    {
        $value = static::get($array, $key, $default);

        if (! is_bool($value)) {
            throw new \UnexpectedValueException("Array value for key [{$key}] is not a boolean.");
        }

        return $value;
    }

    // ─── Writing ──────────────────────────────────────────────────────────

    /** Set a dot path only when it is missing or null. */
    public static function add(array $array, string $key, mixed $value): array
    {
        if (static::get($array, $key) === null) {
            static::set($array, $key, $value);
        }

        return $array;
    }

    /** Put a value on the front, optionally with a key. */
    public static function prepend(array $array, mixed $value, mixed $key = null): array
    {
        if ($key === null) {
            array_unshift($array, $value);

            return $array;
        }

        return [$key => $value] + $array;
    }

    /** Add a prefix to every key. */
    public static function prependKeysWith(array $array, string $prefix): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $result[$prefix . $key] = $value;
        }

        return $result;
    }

    // ─── Reshaping ────────────────────────────────────────────────────────

    /** Merge one level of nested arrays into a single array. */
    public static function collapse(array $array): array
    {
        $result = [];

        foreach ($array as $values) {
            if (is_array($values)) {
                $result[] = $values;
            }
        }

        return $result === [] ? [] : array_merge(...$result);
    }

    /** Split into a keys array and a values array. */
    public static function divide(array $array): array
    {
        return [array_keys($array), array_values($array)];
    }

    /**
     * Flatten to a single level, with dot-notation keys.
     */
    public static function dot(array $array, string $prepend = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && $value !== []) {
                $result = array_merge($result, static::dot($value, $prepend . $key . '.'));
                continue;
            }

            $result[$prepend . $key] = $value;
        }

        return $result;
    }

    /** Expand dot-notation keys back into nested arrays. */
    public static function undot(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            static::set($result, (string) $key, $value);
        }

        return $result;
    }

    /** The cartesian product of the given arrays. */
    public static function crossJoin(array ...$arrays): array
    {
        $results = [[]];

        foreach ($arrays as $array) {
            $append = [];

            foreach ($results as $product) {
                foreach ($array as $item) {
                    $product[] = $item;
                    $append[] = $product;
                    array_pop($product);
                }
            }

            $results = $append;
        }

        return $results;
    }

    /** Re-key the array by one of each item's fields. */
    public static function keyBy(array $array, string|Closure $keyBy): array
    {
        $result = [];

        foreach ($array as $item) {
            $key = $keyBy instanceof Closure
                ? $keyBy($item)
                : static::get(is_array($item) ? $item : (array) $item, $keyBy);

            $result[$key] = $item;
        }

        return $result;
    }

    /** Map over the array, preserving keys. */
    public static function map(array $array, Closure $callback): array
    {
        $keys = array_keys($array);

        return array_combine($keys, array_map($callback, $array, $keys));
    }

    /** Map, spreading each item's values as arguments. */
    public static function mapSpread(array $array, Closure $callback): array
    {
        return array_map(
            static fn ($chunk) => $callback(...array_values((array) $chunk)),
            $array
        );
    }

    /**
     * Map to new key => value pairs.
     *
     * The callback returns a single-entry array, which is merged into the
     * result — the way to change keys and values in one pass.
     */
    public static function mapWithKeys(array $array, Closure $callback): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            foreach ($callback($value, $key) as $newKey => $newValue) {
                $result[$newKey] = $newValue;
            }
        }

        return $result;
    }

    /** Split into [passing, failing] by a predicate. */
    public static function partition(array $array, Closure $callback): array
    {
        $passed = [];
        $failed = [];

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                $passed[$key] = $value;
            } else {
                $failed[$key] = $value;
            }
        }

        return [$passed, $failed];
    }

    // ─── Filtering ────────────────────────────────────────────────────────

    /** Keep the items a predicate accepts, preserving keys. */
    public static function where(array $array, Closure $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /** Drop the items a predicate accepts. */
    public static function reject(array $array, Closure $callback): array
    {
        return array_filter(
            $array,
            static fn ($value, $key) => ! $callback($value, $key),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** Drop null values. */
    public static function whereNotNull(array $array): array
    {
        return array_filter($array, static fn ($value) => $value !== null);
    }

    /** Whether every item satisfies the predicate. */
    public static function every(array $array, Closure $callback): bool
    {
        foreach ($array as $key => $value) {
            if (! $callback($value, $key)) {
                return false;
            }
        }

        return true;
    }

    /** Whether any item satisfies the predicate. */
    public static function some(array $array, Closure $callback): bool
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }

    /** Keep only the given values, reindexed. */
    public static function onlyValues(array $array, array $values): array
    {
        return array_values(array_intersect($array, $values));
    }

    /** Drop the given values, reindexed. */
    public static function exceptValues(array $array, array $values): array
    {
        return array_values(array_diff($array, $values));
    }

    /** Keep only the named keys from each item. */
    public static function select(array $array, array|string $keys): array
    {
        $keys = (array) $keys;

        return array_map(
            static function ($item) use ($keys) {
                $item = is_array($item) ? $item : (array) $item;
                $result = [];

                foreach ($keys as $key) {
                    if (array_key_exists($key, $item)) {
                        $result[$key] = $item[$key];
                    }
                }

                return $result;
            },
            $array
        );
    }

    // ─── Ordering and sampling ────────────────────────────────────────────

    public static function sort(array $array, ?Closure $callback = null): array
    {
        $callback === null ? asort($array) : uasort($array, $callback);

        return $array;
    }

    public static function sortDesc(array $array): array
    {
        arsort($array);

        return $array;
    }

    /** Sort the array and every nested array within it. */
    public static function sortRecursive(array $array, int $options = SORT_REGULAR, bool $descending = false): array
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $value = static::sortRecursive($value, $options, $descending);
            }
        }
        unset($value);

        if (array_is_list($array)) {
            $descending ? rsort($array, $options) : sort($array, $options);
        } else {
            $descending ? krsort($array, $options) : ksort($array, $options);
        }

        return $array;
    }

    public static function sortRecursiveDesc(array $array, int $options = SORT_REGULAR): array
    {
        return static::sortRecursive($array, $options, true);
    }

    public static function shuffle(array $array): array
    {
        shuffle($array);

        return $array;
    }

    /**
     * One random item, or $number of them.
     *
     * @throws \InvalidArgumentException When more are requested than exist.
     */
    public static function random(array $array, ?int $number = null): mixed
    {
        $count = count($array);
        $requested = $number ?? 1;

        if ($requested > $count) {
            throw new \InvalidArgumentException(
                "Cannot take {$requested} items from an array of {$count}."
            );
        }

        if ($count === 0 || $requested === 0) {
            return $number === null ? null : [];
        }

        $keys = (array) array_rand($array, $requested);

        if ($number === null) {
            return $array[$keys[0]];
        }

        return array_map(static fn ($key) => $array[$key], $keys);
    }

    /** The first $limit items, or the last when negative. */
    public static function take(array $array, int $limit): array
    {
        return $limit < 0
            ? array_slice($array, $limit, abs($limit))
            : array_slice($array, 0, $limit);
    }

    // ─── Output ───────────────────────────────────────────────────────────

    /**
     * Join the values, with a different separator before the last one.
     */
    public static function join(array $array, string $glue, string $finalGlue = ''): string
    {
        if ($finalGlue === '' || count($array) < 2) {
            return implode($glue, $array);
        }

        $values = array_values($array);
        $last = array_pop($values);

        return implode($glue, $values) . $finalGlue . $last;
    }

    /** The array as a URL query string. */
    public static function query(array $array): string
    {
        return http_build_query($array, '', '&', PHP_QUERY_RFC3986);
    }
}
