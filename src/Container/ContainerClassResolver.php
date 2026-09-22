<?php

namespace Nitro\Container;

use Illuminate\Contracts\Container\Container as ContainerContract;
use Nitro\Container\Contracts\ClassResolver;

/**
 * The container, seen through {@see ClassResolver}.
 *
 * A provider builds one of these instead of handing a class the container
 * itself. Nothing is added — construction is still the container's — but the
 * receiving class is typed against one method rather than the whole surface.
 *
 * The container cannot implement the interface directly: its own make() takes
 * a second parameter and returns mixed, which no covariant signature can
 * narrow to object.
 */
final class ContainerClassResolver implements ClassResolver
{
    public function __construct(private ContainerContract $container) {}

    /**
     * @template TClass of object
     *
     * @param  class-string<TClass> $class
     * @return TClass
     */
    public function resolve(string $class): object
    {
        return $this->container->make($class);
    }
}
