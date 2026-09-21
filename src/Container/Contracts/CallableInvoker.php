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
    /**
     * @param  array<array-key, mixed> $parameters Values that override resolution, by name or position.
     */
    public function call(callable $callable, array $parameters = []): mixed;

    /**
     * The argument list a method would be called with, without calling it.
     *
     * @param  array<array-key, mixed> $parameters
     * @return array<int, mixed>
     */
    public function arguments(object|string $object, string $method, array $parameters = []): array;
}
