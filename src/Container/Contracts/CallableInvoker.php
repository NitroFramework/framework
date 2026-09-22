<?php

namespace Nitro\Container\Contracts;

/**
 * Calls a callable, filling its parameters from their declared types.
 *
 * The second capability a class needs from a container without needing the
 * container: run a method whose signature it does not control — a controller
 * action, a component's mount(), a job's handle(). Given values win over
 * resolved ones, so route parameters and request data pass straight through.
 */
interface CallableInvoker
{
    /** Returned by a parameter binder that declines to bind a value. */
    public const PARAM_UNRESOLVED = "\0nitro:param-unresolved";

    /**
     * Register the resolver that turns a route value into the object its
     * parameter is typed for.
     *
     * Called as ($type, $value, $name), returning PARAM_UNRESOLVED to decline
     * and let the value through as it stands. Declared here rather than on the
     * container because binding a route segment to a model is a routing
     * policy, and the only thing it needs from a container is the invocation
     * it is about to take part in.
     */
    public function bindParametersUsing(\Closure $resolver): void;

    /**
     * @param  callable|string|array<int, mixed> $callable   A closure, function name, [object, method] pair,
     *                                                      invokable object, or "Class@method" string.
     * @param  array<array-key, mixed>           $parameters Values that override resolution, by name or position.
     */
    public function call(callable|string|array $callable, array $parameters = []): mixed;

    /**
     * The argument list a method would be called with, without calling it.
     *
     * @param  array<array-key, mixed> $parameters
     * @return array<int, mixed>
     */
    public function arguments(object|string $object, string $method, array $parameters = []): array;
}
