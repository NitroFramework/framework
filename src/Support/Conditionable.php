<?php

namespace Nitro\Support;

use Closure;

/**
 * Applies a step to a fluent chain only when a condition holds.
 *
 *     $query->when($request->filled('search'), fn ($q) => $q->where(...))
 *           ->unless($user->isAdmin(), fn ($q) => $q->whereNull('hidden_at'));
 */
trait Conditionable
{
    /**
     * Run the callback when the condition is truthy.
     *
     * A closure condition is resolved against this object. The callback's
     * return value continues the chain; returning null continues with $this.
     *
     * @param  mixed         $condition Truthy test, or a closure resolving to one.
     * @param  callable      $callback  Applied when the condition holds.
     * @param  callable|null $default   Applied when it does not.
     * @return mixed
     */
    public function when(mixed $condition, callable $callback, ?callable $default = null): mixed
    {
        $condition = $condition instanceof Closure ? $condition($this) : $condition;

        if ($condition) {
            return $callback($this, $condition) ?? $this;
        }

        if ($default !== null) {
            return $default($this, $condition) ?? $this;
        }

        return $this;
    }

    /**
     * Run the callback when the condition is falsy.
     *
     * @param  mixed         $condition Truthy test, or a closure resolving to one.
     * @param  callable      $callback  Applied when the condition does not hold.
     * @param  callable|null $default   Applied when it does.
     * @return mixed
     */
    public function unless(mixed $condition, callable $callback, ?callable $default = null): mixed
    {
        $condition = $condition instanceof Closure ? $condition($this) : $condition;

        return $this->when(! $condition, $callback, $default);
    }
}
