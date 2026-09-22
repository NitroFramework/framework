<?php

namespace Nitro\Container\Exceptions;

use Illuminate\Contracts\Container\BindingResolutionException as IlluminateBindingResolutionException;

/**
 * The container could not work out how to build something.
 *
 * A class that does not exist or cannot be instantiated, a parameter with no
 * type, no default and no override, a dependency chain that loops back on
 * itself. Its own failures, as distinct from an exception a constructor threw
 * while the container was building it — which passes through untouched, so
 * that an optional dependency falling back to its default never hides one.
 *
 * Extends the container package's own resolution exception, so a caller may
 * catch either type and see the same failure.
 */
class BindingResolutionException extends IlluminateBindingResolutionException
{
}
