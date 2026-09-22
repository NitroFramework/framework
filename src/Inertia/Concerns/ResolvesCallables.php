<?php

namespace Nitro\Inertia\Concerns;

use Nitro\Container\Contracts\CallableInvoker;

/**
 * Turns a prop's value into data, calling it if it is something callable.
 */
trait ResolvesCallables
{
    /**
     * Resolve a value, invoking it through the container when it is callable
     * so a closure prop can ask for its own dependencies.
     *
     * A string is left alone even though PHP would accept it as a callable:
     * a prop whose value is the word 'count' is a string the page wants, not
     * a function to call.
     */
    protected function resolveCallable(mixed $value): mixed
    {
        return is_object($value) && is_callable($value)
            ? app(CallableInvoker::class)->call($value)
            : $value;
    }
}
