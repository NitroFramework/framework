<?php

namespace Nitro\Support;

/**
 * What `$collection->map->name` returns between the two arrows.
 *
 *     $users->map->name          // instead of ->map(fn ($u) => $u->name)
 *     $users->each->activate()   // instead of ->each(fn ($u) => $u->activate())
 *     $orders->sum->total        // instead of ->sum(fn ($o) => $o->total)
 *
 * The collection hands one of these back from __get(); reading a property off
 * it calls the collection's method with a closure that reads that property
 * from each item, and calling a method does the same with a method call.
 *
 * Worth the indirection because the closure form is nearly all punctuation:
 * the interesting words in `fn ($u) => $u->name` are `map` and `name`, and
 * this leaves only those.
 */
class HigherOrderCollectionProxy
{
    public function __construct(
        protected Collection $collection,
        protected string $method,
    ) {}

    /** `$users->map->name` — the property read from every item. */
    public function __get(string $key)
    {
        return $this->collection->{$this->method}(
            static fn ($item) => is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null)
        );
    }

    /**
     * `$users->each->activate()` — the method called on every item.
     *
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters)
    {
        return $this->collection->{$this->method}(
            static fn ($item) => $item->{$method}(...$parameters)
        );
    }
}
