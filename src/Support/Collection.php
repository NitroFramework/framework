<?php

namespace Nitro\Support;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use JsonSerializable;

/**
 * A fluent, array-backed collection with map/filter/reduce helpers over query and model results.
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    use Conditionable;
    use Macroable;

    protected array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * The methods that can be written `$collection->map->name`.
     *
     * A fixed list rather than any method, because the shorthand only reads
     * well where the callback takes one item and returns something about it.
     *
     * @var array<int, string>
     */
    protected static array $proxies = [
        'average', 'avg', 'contains', 'doesntContain', 'each', 'every', 'filter',
        'first', 'flatMap', 'groupBy', 'hasSole', 'keyBy', 'last', 'map', 'max',
        'min', 'partition', 'percentage', 'reject', 'skipUntil', 'skipWhile',
        'some', 'sortBy', 'sortByDesc', 'sum', 'takeUntil', 'takeWhile', 'unique',
    ];

    /**
     * Hand back a proxy for the shorthand forms.
     *
     * @throws \InvalidArgumentException When the name is not one of them.
     */
    public function __get(string $key)
    {
        if (! in_array($key, static::$proxies, true)) {
            throw new \InvalidArgumentException(
                "Collection has no property [{$key}], and it is not one of the shorthand methods."
            );
        }

        return new HigherOrderCollectionProxy($this, $key);
    }

    // =========================================================================
    // RETRIEVAL
    // =========================================================================

    public function all(): array
    {
        return $this->items;
    }

    public function first(?callable $callback = null, $default = null)
    {
        if ($callback === null) {
            // First element by iteration order — NOT $items[0], which is null
            // for any associative/keyed collection (keyBy, groupBy, pluck…).
            foreach ($this->items as $item) {
                return $item;
            }
            return $default;
        }

        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }

        return $default;
    }

    public function last(?callable $callback = null, $default = null)
    {
        if ($callback === null) {
            if (empty($this->items)) {
                return $default;
            }
            return end($this->items);
        }

        return $this->reverse()->first($callback, $default);
    }

    public function get(int|string $key, $default = null)
    {
        return $this->items[$key] ?? $default;
    }

    public function value(string $column, $default = null)
    {
        $first = $this->first();

        if ($first === null) {
            return $default;
        }

        if (is_object($first)) {
            return $first->$column ?? $default;
        }

        if (is_array($first)) {
            return $first[$column] ?? $default;
        }

        return $default;
    }

    public function firstWhere(string $key, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->first(function ($item) use ($key, $operator, $value) {
            $itemValue = $this->getItemValue($item, $key);
            return $this->compareValues($itemValue, $operator, $value);
        });
    }

    public function find($key, $default = null)
    {
        if ($key instanceof self) {
            $key = $key->all();
        }

        if (is_array($key)) {
            return $this->filter(fn($item) => in_array(
                is_object($item) && method_exists($item, 'getKey') ? $item->getKey() : null,
                $key,
                false
            ));
        }

        foreach ($this->items as $item) {
            if (is_object($item) && method_exists($item, 'getKey') && $item->getKey() == $key) {
                return $item;
            }
        }

        return $default;
    }

    public function pull(int|string $key, $default = null)
    {
        $value = $this->items[$key] ?? $default;
        unset($this->items[$key]);
        return $value;
    }

    public function random(int $count = 1)
    {
        if ($this->isEmpty()) {
            return $count === 1 ? null : new static();
        }

        $keys = array_rand($this->items, min($count, $this->count()));

        if ($count === 1) {
            return $this->items[$keys];
        }

        return new static(array_map(fn($key) => $this->items[$key], (array) $keys));
    }

    public function search(mixed $value, bool $strict = false)
    {
        if (is_callable($value)) {
            foreach ($this->items as $key => $item) {
                if ($value($item, $key)) {
                    return $key;
                }
            }
            return false;
        }

        return array_search($value, $this->items, $strict);
    }

    // =========================================================================
    // TRANSFORMATION
    // =========================================================================

    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $values = array_map($callback, $this->items, $keys);
        return new static(array_combine($keys, $values));
    }

    public function mapWithKeys(callable $callback): static
    {
        $result = [];

        foreach ($this->items as $key => $item) {
            $pair = $callback($item, $key);
            foreach ($pair as $mapKey => $mapValue) {
                $result[$mapKey] = $mapValue;
            }
        }

        return new static($result);
    }

    public function flatMap(callable $callback): static
    {
        return $this->map($callback)->collapse();
    }

    public function mapInto(string $class): static
    {
        return new static(array_map(fn($item) => new $class($item), $this->items));
    }

    public function mapToGroups(callable $callback): static
    {
        $groups = [];

        foreach ($this->items as $key => $item) {
            $pair = $callback($item, $key);
            foreach ($pair as $groupKey => $groupValue) {
                $groups[$groupKey][] = $groupValue;
            }
        }

        return new static(array_map(fn($group) => new static($group), $groups));
    }

    public function transform(callable $callback): static
    {
        $this->items = array_map($callback, $this->items, array_keys($this->items));
        return $this;
    }

    public function pluck(string $column, ?string $key = null): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $value = $this->getItemValue($item, $column);

            if ($key !== null) {
                $keyValue = $this->getItemValue($item, $key);
                $results[$keyValue] = $value;
            } else {
                $results[] = $value;
            }
        }

        return new static($results);
    }

    public function flatten(int|float $depth = INF): static
    {
        $result = [];

        foreach ($this->items as $item) {
            if ($item instanceof self) {
                $item = $item->all();
            }

            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge(
                    $result,
                    (new static($item))->flatten($depth - 1)->all()
                );
            }
        }

        return new static($result);
    }

    public function collapse(): static
    {
        $result = [];

        foreach ($this->items as $item) {
            if ($item instanceof self) {
                $item = $item->all();
            }

            if (is_array($item)) {
                $result = array_merge($result, $item);
            }
        }

        return new static($result);
    }

    public function flip(): static
    {
        return new static(array_flip($this->items));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    // =========================================================================
    // FILTERING
    // =========================================================================

    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_values(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH)));
        }

        return new static(array_values(array_filter($this->items)));
    }

    public function reject(callable $callback): static
    {
        return $this->filter(fn($item, $key) => !$callback($item, $key));
    }

    public function where(string $key, $operator = null, $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->filter(function ($item) use ($key, $operator, $value) {
            $itemValue = $this->getItemValue($item, $key);
            return $this->compareValues($itemValue, $operator, $value);
        });
    }

    public function whereIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => in_array($this->getItemValue($item, $key), $values, true));
    }

    public function whereNotIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => !in_array($this->getItemValue($item, $key), $values, true));
    }

    public function whereNull(?string $key = null): static
    {
        return $this->filter(fn($item) => $key === null ? $item === null : $this->getItemValue($item, $key) === null);
    }

    public function whereNotNull(?string $key = null): static
    {
        return $this->filter(fn($item) => $key === null ? $item !== null : $this->getItemValue($item, $key) !== null);
    }

    public function whereBetween(string $key, array $range): static
    {
        return $this->filter(function ($item) use ($key, $range) {
            $value = $this->getItemValue($item, $key);
            return $value >= $range[0] && $value <= $range[1];
        });
    }

    public function whereNotBetween(string $key, array $range): static
    {
        return $this->filter(function ($item) use ($key, $range) {
            $value = $this->getItemValue($item, $key);
            return $value < $range[0] || $value > $range[1];
        });
    }

    public function whereInstanceOf(string $class): static
    {
        return $this->filter(fn($item) => $item instanceof $class);
    }

    public function only(array $keys): static
    {
        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    public function except(array $keys): static
    {
        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    public function unique($key = null): static
    {
        // Keys preserved, here and below: unique() is a filter, and a filter
        // that renumbers loses which item survived. values() is there for a
        // caller that wants a list back.
        if ($key === null) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        // A callable as well as a key name, so uniqueness can be by something
        // derived — and so the $collection->unique->age shorthand works.
        $callable = is_callable($key) && ! is_string($key);

        $exists = [];
        $unique = [];

        foreach ($this->items as $itemKey => $item) {
            $value = $callable ? $key($item, $itemKey) : $this->getItemValue($item, $key);

            if (!in_array($value, $exists, true)) {
                $exists[] = $value;
                $unique[$itemKey] = $item;
            }
        }

        return new static($unique);
    }

    public function duplicates(?string $key = null): static
    {
        $seen = [];
        $duplicates = [];

        foreach ($this->items as $index => $item) {
            $value = $key !== null ? $this->getItemValue($item, $key) : $item;

            if (in_array($value, $seen, true)) {
                $duplicates[$index] = $value;
            } else {
                $seen[] = $value;
            }
        }

        return new static($duplicates);
    }

    // =========================================================================
    // SORTING
    // =========================================================================

    public function sort(?callable $callback = null): static
    {
        $items = $this->items;

        // Key-preserving (asort/uasort) to match Laravel — callers use values()
        // to reindex. usort/sort would silently renumber associative keys.
        if ($callback) {
            uasort($items, $callback);
        } else {
            asort($items);
        }

        return new static($items);
    }

    public function sortBy($column, int $options = SORT_REGULAR, bool $descending = false): static
    {
        $items = $this->items;

        uasort($items, function ($a, $b) use ($column, $options, $descending) {
            $aVal = is_callable($column) ? $column($a) : $this->getItemValue($a, $column);
            $bVal = is_callable($column) ? $column($b) : $this->getItemValue($b, $column);

            $result = match ($options) {
                SORT_NUMERIC => $aVal <=> $bVal,
                SORT_STRING => strcmp((string) $aVal, (string) $bVal),
                SORT_NATURAL => strnatcmp((string) $aVal, (string) $bVal),
                SORT_FLAG_CASE => strcasecmp((string) $aVal, (string) $bVal),
                default => $aVal <=> $bVal,
            };

            return $descending ? -$result : $result;
        });

        return new static($items);
    }

    public function sortByDesc($column, int $options = SORT_REGULAR): static
    {
        return $this->sortBy($column, $options, true);
    }

    public function sortKeys(int $options = SORT_REGULAR, bool $descending = false): static
    {
        $items = $this->items;

        $descending ? krsort($items, $options) : ksort($items, $options);

        return new static($items);
    }

    public function sortKeysDesc(int $options = SORT_REGULAR): static
    {
        return $this->sortKeys($options, true);
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);
        return new static($items);
    }

    // =========================================================================
    // GROUPING & PARTITIONING
    // =========================================================================

    public function groupBy($groupBy): static
    {
        $groups = [];

        foreach ($this->items as $key => $item) {
            $groupKey = is_callable($groupBy)
                ? $groupBy($item, $key)
                : $this->getItemValue($item, $groupBy);

            $groups[$groupKey][] = $item;
        }

        return new static(array_map(fn($group) => new static($group), $groups));
    }

    public function keyBy($keyBy): static
    {
        $results = [];

        foreach ($this->items as $key => $item) {
            $resolvedKey = is_callable($keyBy)
                ? $keyBy($item, $key)
                : $this->getItemValue($item, $keyBy);

            $results[$resolvedKey] = $item;
        }

        return new static($results);
    }

    public function partition(callable $callback): static
    {
        $passed = [];
        $failed = [];

        // Keyed rather than renumbered: partitioning a keyed collection and
        // getting two lists back loses which item was which, and the caller
        // can always call values() when it wants a list.
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                $passed[$key] = $item;
            } else {
                $failed[$key] = $item;
            }
        }

        return new static([new static($passed), new static($failed)]);
    }

    public function chunk(int $size): static
    {
        if ($size <= 0) {
            return new static();
        }

        return new static(
            array_map(
                fn($chunk) => new static($chunk),
                array_chunk($this->items, $size, true)
            )
        );
    }

    public function split(int $groups): static
    {
        if ($this->isEmpty()) {
            return new static();
        }

        $result = [];
        $groupSize = (int) ceil($this->count() / $groups);

        foreach (array_chunk($this->items, max(1, $groupSize)) as $chunk) {
            $result[] = new static($chunk);
        }

        return new static($result);
    }

    public function nth(int $step, int $offset = 0): static
    {
        $result = [];
        $position = 0;

        foreach ($this->items as $item) {
            if ($position % $step === $offset) {
                $result[] = $item;
            }
            $position++;
        }

        return new static($result);
    }

    // =========================================================================
    // AGGREGATION
    // =========================================================================

    public function sum($key = null): int|float
    {
        if ($key === null) {
            return array_sum($this->items);
        }

        if (is_callable($key)) {
            return array_sum(array_map($key, $this->items));
        }

        return array_sum($this->pluck($key)->all());
    }

    public function avg($key = null): int|float|null
    {
        // Exclude nulls from BOTH the sum and the divisor — a null value must not
        // count as a zero in the numerator nor pad the denominator, or the mean is
        // skewed. Matches Laravel's avg(), which only folds non-null values.
        $values = [];
        foreach ($this->items as $item) {
            $value = $key === null
                ? $item
                : (is_callable($key) ? $key($item) : $this->getItemValue($item, $key));
            if ($value !== null) {
                $values[] = $value;
            }
        }

        $count = count($values);
        if ($count === 0) {
            return null;
        }

        return array_sum($values) / $count;
    }

    public function average($key = null): int|float|null
    {
        return $this->avg($key);
    }

    public function min($key = null)
    {
        // A callable, like sum() and avg() already took: needed for the
        // $collection->min->age shorthand, and for anything derived rather
        // than read straight off a key.
        if (is_callable($key) && ! is_string($key)) {
            return (new static(array_map($key, $this->items)))->min();
        }

        if ($key !== null) {
            return $this->pluck($key)->min();
        }

        // PHP's min() throws a ValueError on an empty array — return null like Laravel.
        return empty($this->items) ? null : min($this->items);
    }

    public function max($key = null)
    {
        if (is_callable($key) && ! is_string($key)) {
            return (new static(array_map($key, $this->items)))->max();
        }

        if ($key !== null) {
            return $this->pluck($key)->max();
        }

        return empty($this->items) ? null : max($this->items);
    }

    public function median($key = null): int|float|null
    {
        // Filter NULLs only — a bare filter() would also drop legitimate 0/0.0/''
        // values and skew the median (Laravel filters `fn($v) => !is_null($v)`).
        $notNull = static fn($value): bool => $value !== null;

        $values = $key !== null
            ? $this->pluck($key)->filter($notNull)->sort()->values()->all()
            : $this->filter($notNull)->sort()->values()->all();

        $count = count($values);

        if ($count === 0) {
            return null;
        }

        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    public function mode($key = null): ?array
    {
        $values = $key !== null ? $this->pluck($key)->all() : $this->items;

        if (empty($values)) {
            return null;
        }

        $counts = array_count_values(
            array_map(fn($value) => is_object($value) ? spl_object_hash($value) : (string) $value, $values)
        );

        $maxCount = max($counts);

        // Map back to original values
        $valueMap = [];
        foreach ($values as $value) {
            $hash = is_object($value) ? spl_object_hash($value) : (string) $value;
            $valueMap[$hash] = $value;
        }

        $modes = [];
        foreach ($counts as $hash => $count) {
            if ($count === $maxCount) {
                $modes[] = $valueMap[$hash];
            }
        }

        return $modes;
    }

    public function countBy(?callable $callback = null): static
    {
        if ($callback === null) {
            return new static(array_count_values(
                array_map(fn($value) => (string) $value, $this->items)
            ));
        }

        $counts = [];

        foreach ($this->items as $key => $item) {
            $groupKey = (string) $callback($item, $key);
            $counts[$groupKey] = ($counts[$groupKey] ?? 0) + 1;
        }

        return new static($counts);
    }

    // =========================================================================
    // COMBINATION & MERGING
    // =========================================================================

    public function merge($items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }

        return new static(array_merge($this->items, $items));
    }

    public function mergeRecursive($items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }

        return new static(array_merge_recursive($this->items, $items));
    }

    public function combine($values): static
    {
        if ($values instanceof self) {
            $values = $values->all();
        }

        return new static(array_combine($this->items, $values));
    }

    public function union($items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }

        return new static($this->items + $items);
    }

    public function concat($items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }

        $result = $this->items;

        foreach ($items as $item) {
            $result[] = $item;
        }

        return new static($result);
    }

    public function zip(...$arrays): static
    {
        $arrays = array_map(fn($array) => $array instanceof self ? $array->all() : $array, $arrays);

        return new static(array_map(
            fn(...$items) => new static($items),
            $this->items,
            ...$arrays
        ));
    }

    public function crossJoin(...$arrays): static
    {
        $arrays = array_map(fn($array) => $array instanceof self ? $array->all() : $array, $arrays);

        $result = [[]];

        foreach (array_merge([$this->items], $arrays) as $list) {
            $newResult = [];
            foreach ($result as $existing) {
                foreach ($list as $item) {
                    $newResult[] = array_merge($existing, [$item]);
                }
            }
            $result = $newResult;
        }

        return new static($result);
    }

    // =========================================================================
    // SET OPERATIONS
    // =========================================================================

    public function diff($items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }

        return new static(array_values(array_diff($this->items, $items)));
    }

    public function diffKeys($items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }

        return new static(array_diff_key($this->items, $items));
    }

    public function diffAssoc($items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }

        return new static(array_diff_assoc($this->items, $items));
    }

    public function intersect($items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }

        return new static(array_values(array_intersect($this->items, $items)));
    }

    public function intersectByKeys($items): static
    {
        if ($items instanceof self) {
            $items = $items->all();
        }

        return new static(array_intersect_key($this->items, $items));
    }

    // =========================================================================
    // ADDING & REMOVING
    // =========================================================================

    public function push(...$values): static
    {
        foreach ($values as $value) {
            $this->items[] = $value;
        }

        return $this;
    }

    public function prepend($value, $key = null): static
    {
        if ($key !== null) {
            $this->items = [$key => $value] + $this->items;
        } else {
            array_unshift($this->items, $value);
        }

        return $this;
    }

    public function put(int|string $key, $value): static
    {
        $this->items[$key] = $value;
        return $this;
    }

    public function pop(int $count = 1)
    {
        if ($count === 1) {
            return array_pop($this->items);
        }

        if ($this->isEmpty()) {
            return new static();
        }

        $results = [];
        $collectionCount = $this->count();

        foreach (range(1, min($count, $collectionCount)) as $i) {
            $results[] = array_pop($this->items);
        }

        return new static($results);
    }

    public function shift(int $count = 1)
    {
        if ($count === 1) {
            return array_shift($this->items);
        }

        if ($this->isEmpty()) {
            return new static();
        }

        $results = [];
        $collectionCount = $this->count();

        foreach (range(1, min($count, $collectionCount)) as $i) {
            $results[] = array_shift($this->items);
        }

        return new static($results);
    }

    public function forget(int|string|array $keys): static
    {
        foreach ((array) $keys as $key) {
            unset($this->items[$key]);
        }

        return $this;
    }

    public function splice(int $offset, ?int $length = null, array $replacement = []): static
    {
        if ($length === null) {
            return new static(array_splice($this->items, $offset));
        }

        return new static(array_splice($this->items, $offset, $length, $replacement));
    }

    // =========================================================================
    // SLICING
    // =========================================================================

    public function take(int $count): static
    {
        if ($count < 0) {
            return new static(array_slice($this->items, $count));
        }

        return new static(array_slice($this->items, 0, $count));
    }

    public function takeUntil($value): static
    {
        $callback = is_callable($value) ? $value : fn($item) => $item === $value;
        $result = [];

        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                break;
            }
            $result[] = $item;
        }

        return new static($result);
    }

    public function takeWhile($value): static
    {
        $callback = is_callable($value) ? $value : fn($item) => $item === $value;
        $result = [];

        foreach ($this->items as $key => $item) {
            if (!$callback($item, $key)) {
                break;
            }
            $result[] = $item;
        }

        return new static($result);
    }

    public function skip(int $count): static
    {
        return new static(array_values(array_slice($this->items, $count)));
    }

    public function skipUntil($value): static
    {
        $callback = is_callable($value) ? $value : fn($item) => $item === $value;
        $result = [];
        $found = false;

        foreach ($this->items as $key => $item) {
            if (!$found && $callback($item, $key)) {
                $found = true;
            }

            if ($found) {
                $result[] = $item;
            }
        }

        return new static($result);
    }

    public function skipWhile($value): static
    {
        $callback = is_callable($value) ? $value : fn($item) => $item === $value;
        $result = [];
        $skipping = true;

        foreach ($this->items as $key => $item) {
            if ($skipping && !$callback($item, $key)) {
                $skipping = false;
            }

            if (!$skipping) {
                $result[] = $item;
            }
        }

        return new static($result);
    }

    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    // =========================================================================
    // ITERATION
    // =========================================================================

    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    public function eachSpread(callable $callback): static
    {
        return $this->each(function ($item) use ($callback) {
            return $callback(...(is_array($item) ? $item : [$item]));
        });
    }

    public function reduce(callable $callback, $initial = null)
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function every(callable $callback): bool
    {
        foreach ($this->items as $key => $item) {
            if (!$callback($item, $key)) {
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // CONDITIONALS
    // =========================================================================

    // when() and unless() come from Conditionable.

    // =========================================================================
    // CHECKING
    // =========================================================================

    public function contains($key, $operator = null, $value = null): bool
    {
        if (func_num_args() === 1) {
            if (is_callable($key)) {
                foreach ($this->items as $itemKey => $item) {
                    if ($key($item, $itemKey)) {
                        return true;
                    }
                }
                return false;
            }

            return in_array($key, $this->items, true);
        }

        return $this->where(...func_get_args())->isNotEmpty();
    }

    public function containsStrict($key, $value = null): bool
    {
        if (func_num_args() === 2) {
            return $this->contains(fn($item) => $this->getItemValue($item, $key) === $value);
        }

        return in_array($key, $this->items, true);
    }

    public function doesntContain($key, $operator = null, $value = null): bool
    {
        return !$this->contains(...func_get_args());
    }

    public function has(int|string|array $key): bool
    {
        $keys = (array) $key;

        foreach ($keys as $candidateKey) {
            if (!array_key_exists($candidateKey, $this->items)) {
                return false;
            }
        }

        return true;
    }

    public function hasAny(int|string|array $key): bool
    {
        $keys = (array) $key;

        foreach ($keys as $candidateKey) {
            if (array_key_exists($candidateKey, $this->items)) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    // =========================================================================
    // STRING
    // =========================================================================

    public function implode(string $glue, ?string $key = null): string
    {
        if ($key !== null) {
            return implode($glue, $this->pluck($key)->all());
        }

        return implode($glue, $this->items);
    }

    public function join(string $glue, string $finalGlue = ''): string
    {
        if ($finalGlue === '' || $this->count() <= 1) {
            return $this->implode($glue);
        }

        $last = $this->pop();
        return $this->implode($glue) . $finalGlue . $last;
    }

    // =========================================================================
    // PIPELINE
    // =========================================================================

    public function tap(callable $callback): static
    {
        $callback($this);
        return $this;
    }

    public function pipe(callable $callback)
    {
        return $callback($this);
    }

    public function pipeInto(string $class)
    {
        return new $class($this);
    }

    public function pipeThrough(array $pipes)
    {
        return (new static($pipes))->reduce(
            fn($carry, $pipe) => $pipe($carry),
            $this
        );
    }

    // =========================================================================
    // DEBUGGING
    // =========================================================================

    public function dump(): static
    {
        var_dump($this->items);
        return $this;
    }

    public function dd(): never
    {
        $this->dump();
        exit(1);
    }

    // =========================================================================
    // SERIALIZATION
    // =========================================================================

    public function toArray(): array
    {
        return array_map(function ($item) {
            if ($item instanceof self) {
                return $item->toArray();
            }
            if (is_object($item) && method_exists($item, 'toArray')) {
                return $item->toArray();
            }
            return $item;
        }, $this->items);
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    // =========================================================================
    // STATIC CONSTRUCTORS
    // =========================================================================

    public static function make(array $items = []): static
    {
        return new static($items);
    }

    public static function wrap($value): static
    {
        if ($value instanceof self) {
            return $value;
        }

        return new static(is_array($value) ? $value : [$value]);
    }

    public static function unwrap($value): array
    {
        if ($value instanceof self) {
            return $value->all();
        }

        return $value;
    }

    public static function times(int $count, ?callable $callback = null): static
    {
        if ($count < 1) {
            return new static();
        }

        $items = range(1, $count);

        if ($callback) {
            $items = array_map($callback, $items);
        }

        return new static($items);
    }

    public static function range(int $from, int $to): static
    {
        return new static(range($from, $to));
    }

    public static function empty(): static
    {
        return new static();
    }

    // =========================================================================
    // INTERFACE IMPLEMENTATIONS
    // =========================================================================

    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        // Null-coalesce so `$collection['missing']` returns null instead of
        // emitting an "Undefined array key" warning — which the worker's strict
        // error handler (FrankenPHP PHP 8.5) would otherwise promote to a 500.
        return $this->items[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    protected function getItemValue($item, string $key)
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }

        if (is_object($item)) {
            if (method_exists($item, 'getAttribute')) {
                return $item->getAttribute($key);
            }
            return $item->$key ?? null;
        }

        return null;
    }

    protected function compareValues($itemValue, string $operator, $value): bool
    {
        return match ($operator) {
            '='     => $itemValue == $value,
            '=='    => $itemValue == $value,
            '==='   => $itemValue === $value,
            '!='    => $itemValue != $value,
            '<>'    => $itemValue != $value,
            '!=='   => $itemValue !== $value,
            '<'     => $itemValue < $value,
            '>'     => $itemValue > $value,
            '<='    => $itemValue <= $value,
            '>='    => $itemValue >= $value,
            default => $itemValue == $value,
        };
    }

    // =========================================================================
    // EXACTLY ONE
    // =========================================================================

    /**
     * The only matching item, insisting there is exactly one.
     *
     * first() hides two different bugs: no match at all, and more than one
     * where the code assumed a unique row. Both return something plausible and
     * go wrong later. This says which happened, at the point it happened.
     *
     * @throws \RuntimeException When none match, or more than one does.
     */
    public function sole($key = null, $operator = null, $value = null)
    {
        $matching = func_num_args() === 0
            ? $this
            : (is_callable($key) && ! is_string($key)
                ? $this->filter($key)
                : $this->where(...func_get_args()));

        $count = $matching->count();

        if ($count === 0) {
            throw new ItemNotFoundException('No matching item was found.');
        }

        if ($count > 1) {
            throw new MultipleItemsFoundException($count);
        }

        return $matching->first();
    }

    /**
     * The first item, insisting there is one.
     *
     * @throws \RuntimeException When nothing matches.
     */
    public function firstOrFail($key = null, $operator = null, $value = null)
    {
        $matching = func_num_args() === 0
            ? $this
            : (is_callable($key) && ! is_string($key)
                ? $this->filter($key)
                : $this->where(...func_get_args()));

        if ($matching->isEmpty()) {
            throw new ItemNotFoundException('No matching item was found.');
        }

        return $matching->first();
    }

    /** Whether exactly one item matches, without throwing when it does not. */
    public function hasSole($key = null, $operator = null, $value = null): bool
    {
        try {
            func_num_args() === 0 ? $this->sole() : $this->sole(...func_get_args());

            return true;
        } catch (ItemNotFoundException | MultipleItemsFoundException) {
            return false;
        }
    }

    public function containsOneItem(): bool
    {
        return $this->count() === 1;
    }

    public function containsManyItems(): bool
    {
        return $this->count() > 1;
    }

    // =========================================================================
    // NEIGHBOURS
    // =========================================================================

    /**
     * The item before this one, or null at the start.
     *
     * @param mixed $item A value, or a callback answering which item to find.
     */
    public function before($item, $strict = false)
    {
        $key = $this->keyOf($item, $strict);

        if ($key === null) {
            return null;
        }

        $keys = array_keys($this->items);
        $position = array_search($key, $keys, true);

        return $position > 0 ? $this->items[$keys[$position - 1]] : null;
    }

    /** The item after this one, or null at the end. */
    public function after($item, $strict = false)
    {
        $key = $this->keyOf($item, $strict);

        if ($key === null) {
            return null;
        }

        $keys = array_keys($this->items);
        $position = array_search($key, $keys, true);

        return $position !== false && $position < count($keys) - 1
            ? $this->items[$keys[$position + 1]]
            : null;
    }

    /** The key an item sits at, or null. */
    protected function keyOf($item, bool $strict = false)
    {
        if (is_callable($item) && ! is_string($item)) {
            foreach ($this->items as $key => $value) {
                if ($item($value, $key)) {
                    return $key;
                }
            }

            return null;
        }

        $key = array_search($item, $this->items, $strict);

        return $key === false ? null : $key;
    }

    // =========================================================================
    // KEYS AND SHAPE
    // =========================================================================

    /**
     * Flatten to a single level, keys joined with dots.
     *
     * ['user' => ['name' => 'Ada']] becomes ['user.name' => 'Ada'], which is
     * the shape a form's field names and a validation error bag are already in.
     */
    public function dot(string $prepend = ''): static
    {
        $flattened = [];

        foreach ($this->items as $key => $value) {
            if (is_array($value) && $value !== []) {
                $flattened += (new static($value))->dot($prepend . $key . '.')->all();

                continue;
            }

            $flattened[$prepend . $key] = $value;
        }

        return new static($flattened);
    }

    /** The inverse of {@see dot()}. */
    public function undot(): static
    {
        $expanded = [];

        foreach ($this->items as $key => $value) {
            $segments = explode('.', (string) $key);
            $target = &$expanded;

            foreach ($segments as $index => $segment) {
                if ($index === count($segments) - 1) {
                    break;
                }

                if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                    $target[$segment] = [];
                }

                $target = &$target[$segment];
            }

            $target[end($segments)] = $value;

            unset($target);
        }

        return new static($expanded);
    }

    /** Collapse one level, keeping the inner keys rather than renumbering. */
    public function collapseWithKeys(): static
    {
        $collapsed = [];

        foreach ($this->items as $values) {
            if (is_array($values) || $values instanceof self) {
                $collapsed += $values instanceof self ? $values->all() : $values;
            }
        }

        return new static($collapsed);
    }

    /** Overwrite by key, one level deep. */
    public function replace($items): static
    {
        return new static(array_replace($this->items, $this->itemsFrom($items)));
    }

    /** Overwrite by key, all the way down. */
    public function replaceRecursive($items): static
    {
        return new static(array_replace_recursive($this->items, $this->itemsFrom($items)));
    }

    /**
     * Keep only these keys from every item.
     *
     * For trimming rows to what a response should carry, without mapping each
     * one by hand.
     *
     * @param array<int, string>|string $keys
     */
    public function select($keys): static
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        return $this->map(function ($item) use ($keys) {
            $selected = [];

            foreach ($keys as $key) {
                $value = $this->getItemValue($item, $key);

                if ($value !== null || (is_array($item) && array_key_exists($key, $item))) {
                    $selected[$key] = $value;
                }
            }

            return $selected;
        });
    }

    /** Read a key, putting the value there first when it is absent. */
    public function getOrPut($key, $value)
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $this->items[$key] = is_callable($value) && ! is_string($value) ? $value() : $value;

        return $this->items[$key];
    }

    // =========================================================================
    // WINDOWS AND CHUNKS
    // =========================================================================

    /**
     * Overlapping windows of $size, moving $step at a time.
     *
     * sliding(2) gives consecutive pairs, which is what comparing each item to
     * the next one needs — a gap between dates, a change between readings.
     */
    public function sliding(int $size = 2, int $step = 1): static
    {
        $chunks = [];
        $values = array_values($this->items);
        $count = count($values);

        for ($offset = 0; $offset + $size <= $count; $offset += $step) {
            $chunks[] = new static(array_slice($values, $offset, $size, true));
        }

        return new static($chunks);
    }

    /**
     * Break where the callback says to, rather than at a fixed size.
     *
     * The callback is given the current value, its key and the chunk so far,
     * and returns whether the value belongs with what came before.
     */
    public function chunkWhile(callable $callback): static
    {
        $chunks = [];
        $chunk = [];

        foreach ($this->items as $key => $value) {
            if ($chunk === []) {
                $chunk[$key] = $value;

                continue;
            }

            if ($callback($value, $key, new static($chunk))) {
                $chunk[$key] = $value;

                continue;
            }

            $chunks[] = new static($chunk);
            $chunk = [$key => $value];
        }

        if ($chunk !== []) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    /** Break each time the callback's answer changes. */
    public function chunkBy(callable $callback): static
    {
        $previous = null;
        $first = true;

        return $this->chunkWhile(function ($value, $key) use ($callback, &$previous, &$first): bool {
            $current = $callback($value, $key);

            if ($first) {
                $first = false;
                $previous = $current;

                return true;
            }

            $same = $current === $previous;
            $previous = $current;

            return $same;
        });
    }

    /** Split into $groups, filling each in turn rather than spreading evenly. */
    public function splitIn(int $groups): static
    {
        return $this->chunk((int) ceil($this->count() / max(1, $groups)));
    }

    /** One page of items, counting from page 1. */
    public function forPage(int $page, int $perPage): static
    {
        return $this->slice(max(0, ($page - 1) * $perPage), $perPage);
    }

    /**
     * Grow to $size, filling with $value.
     *
     * A negative size pads the front, which is how a fixed-width row is built
     * from a short one.
     */
    public function pad(int $size, $value): static
    {
        return new static(array_pad($this->items, $size, $value));
    }

    // =========================================================================
    // ADDING
    // =========================================================================

    /** Append an item. Laravel's name for push(). */
    public function add($item): static
    {
        $this->items[] = $item;

        return $this;
    }

    /** Put items at the front, keeping their order. */
    public function unshift(...$values): static
    {
        array_unshift($this->items, ...$values);

        return $this;
    }

    /**
     * Repeat the whole collection $times.
     *
     * @return static
     */
    public function multiply(int $times): static
    {
        $repeated = [];

        for ($i = 0; $i < max(0, $times); $i++) {
            foreach ($this->items as $item) {
                $repeated[] = $item;
            }
        }

        return new static($repeated);
    }

    // =========================================================================
    // SORTING
    // =========================================================================

    /** Sort by value, largest first. */
    public function sortDesc(int $options = SORT_REGULAR): static
    {
        $items = $this->items;

        arsort($items, $options);

        return new static($items);
    }

    /** Sort by key, with a comparison of your own. */
    public function sortKeysUsing(callable $comparison): static
    {
        $items = $this->items;

        uksort($items, $comparison);

        return new static($items);
    }

    // =========================================================================
    // CONDITIONALS
    // =========================================================================

    public function whenEmpty(callable $callback, ?callable $default = null)
    {
        return $this->when($this->isEmpty(), $callback, $default);
    }

    public function whenNotEmpty(callable $callback, ?callable $default = null)
    {
        return $this->when($this->isNotEmpty(), $callback, $default);
    }

    public function unlessEmpty(callable $callback, ?callable $default = null)
    {
        return $this->whenNotEmpty($callback, $default);
    }

    public function unlessNotEmpty(callable $callback, ?callable $default = null)
    {
        return $this->whenEmpty($callback, $default);
    }

    // =========================================================================
    // STRICT COMPARISON
    // =========================================================================

    /**
     * The strict variants.
     *
     * Loose comparison treats 0, '0', '' and false as the same value, so a
     * collection of ids with a stray '' silently collapses into one. These
     * compare with ===, which is what an id or a status code wants.
     */
    public function uniqueStrict(?string $key = null): static
    {
        $seen = [];
        $unique = [];

        foreach ($this->items as $itemKey => $item) {
            $value = $key === null ? $item : $this->getItemValue($item, $key);

            if (in_array($value, $seen, true)) {
                continue;
            }

            $seen[] = $value;
            $unique[$itemKey] = $item;
        }

        return new static($unique);
    }

    public function duplicatesStrict(?string $key = null): static
    {
        $seen = [];
        $duplicates = [];

        foreach ($this->items as $itemKey => $item) {
            $value = $key === null ? $item : $this->getItemValue($item, $key);

            if (in_array($value, $seen, true)) {
                $duplicates[$itemKey] = $value;

                continue;
            }

            $seen[] = $value;
        }

        return new static($duplicates);
    }

    public function whereStrict(string $key, $value): static
    {
        return $this->where($key, '===', $value);
    }

    public function whereInStrict(string $key, array $values): static
    {
        return $this->filter(fn ($item): bool => in_array($this->getItemValue($item, $key), $values, true));
    }

    public function whereNotInStrict(string $key, array $values): static
    {
        return $this->reject(fn ($item): bool => in_array($this->getItemValue($item, $key), $values, true));
    }

    public function doesntContainStrict($key, $value = null): bool
    {
        if (func_num_args() === 1) {
            return ! in_array($key, $this->items, true);
        }

        foreach ($this->items as $item) {
            if ($this->getItemValue($item, $key) === $value) {
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // SET OPERATIONS WITH A COMPARISON
    // =========================================================================

    public function diffUsing($items, callable $comparison): static
    {
        return new static(array_udiff($this->items, $this->itemsFrom($items), $comparison));
    }

    public function diffAssocUsing($items, callable $comparison): static
    {
        return new static(array_diff_uassoc($this->items, $this->itemsFrom($items), $comparison));
    }

    public function diffKeysUsing($items, callable $comparison): static
    {
        return new static(array_diff_ukey($this->items, $this->itemsFrom($items), $comparison));
    }

    public function intersectUsing($items, callable $comparison): static
    {
        return new static(array_uintersect($this->items, $this->itemsFrom($items), $comparison));
    }

    public function intersectAssoc($items): static
    {
        return new static(array_intersect_assoc($this->items, $this->itemsFrom($items)));
    }

    public function intersectAssocUsing($items, callable $comparison): static
    {
        return new static(array_intersect_uassoc($this->items, $this->itemsFrom($items), $comparison));
    }

    // =========================================================================
    // MAPPING AND REDUCING
    // =========================================================================

    /**
     * Map, spreading each item's values as separate arguments.
     *
     * What {@see sliding()} and zip() produce is a collection of small arrays,
     * and this is how they are read without indexing into each one.
     */
    public function mapSpread(callable $callback): static
    {
        return $this->map(static function ($chunk, $key) use ($callback) {
            $values = $chunk instanceof self ? $chunk->all() : (array) $chunk;
            $values[] = $key;

            return $callback(...array_values($values));
        });
    }

    /**
     * Map to key => list, collecting every value under the same key.
     *
     * The callback returns a single-entry [key => value] pair.
     */
    public function mapToDictionary(callable $callback): static
    {
        $dictionary = [];

        foreach ($this->items as $key => $item) {
            $pair = $callback($item, $key);

            $pairKey = array_key_first($pair);

            $dictionary[$pairKey][] = $pair[$pairKey];
        }

        return new static($dictionary);
    }

    /** Reduce, spreading a list of carries as separate arguments. */
    public function reduceSpread(callable $callback, ...$initial): array
    {
        $carry = $initial;

        foreach ($this->items as $key => $item) {
            $carry = $callback(...array_merge($carry, [$item, $key]));

            if (! is_array($carry)) {
                throw new \UnexpectedValueException('reduceSpread() expects the callback to return an array.');
            }
        }

        return $carry;
    }

    /** Reduce, with the key passed alongside the value. */
    public function reduceWithKeys(callable $callback, $initial = null)
    {
        $carry = $initial;

        foreach ($this->items as $key => $item) {
            $carry = $callback($carry, $item, $key);
        }

        return $carry;
    }

    /** Reduce into an object, which is passed as the carry. */
    public function reduceInto($object, callable $callback)
    {
        return $this->reduce($callback, $object);
    }

    // =========================================================================
    // MISCELLANY
    // =========================================================================

    /** Whether anything matches. Laravel's name for contains(). */
    public function some($key, $operator = null, $value = null): bool
    {
        return $this->contains(...func_get_args());
    }

    /**
     * Insist every item is of a type, and say which one was not.
     *
     * A collection is only as trustworthy as what was put in it, and the
     * failure otherwise surfaces much later as a call to a method on the wrong
     * kind of object.
     *
     * @param array<int, string>|string $type
     *
     * @throws \UnexpectedValueException
     */
    public function ensure($type): static
    {
        $types = is_array($type) ? $type : [$type];

        foreach ($this->items as $index => $item) {
            foreach ($types as $allowed) {
                if ($item instanceof $allowed || get_debug_type($item) === $allowed) {
                    continue 2;
                }
            }

            throw new \UnexpectedValueException(sprintf(
                'Expected every item to be of type [%s]; item at [%s] is a %s.',
                implode('|', $types),
                $index,
                get_debug_type($item),
            ));
        }

        return $this;
    }

    /** What share of the collection matches, as a percentage. */
    public function percentage(callable $callback, int $precision = 2): ?float
    {
        if ($this->items === []) {
            return null;
        }

        return round($this->filter($callback)->count() / $this->count() * 100, $precision);
    }

    /** A new collection of these items. */
    public function collect(): static
    {
        return new static($this->items);
    }

    /** This collection as a plain one, for a subclass that added behaviour. */
    public function toBase(): self
    {
        return new self($this->items);
    }

    /** Build a collection from a JSON string. */
    public static function fromJson(string $json, int $depth = 512, int $flags = 0): static
    {
        $decoded = json_decode($json, true, $depth, $flags);

        return new static(is_array($decoded) ? $decoded : []);
    }

    /** JSON a person can read. */
    public function toPrettyJson(int $options = 0): string
    {
        return $this->toJson($options | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Items from a collection, an array, or anything iterable.
     *
     * @return array<mixed>
     */
    protected function itemsFrom($items): array
    {
        return match (true) {
            $items instanceof self => $items->all(),
            is_array($items) => $items,
            is_iterable($items) => iterator_to_array($items),
            default => (array) $items,
        };
    }
}
