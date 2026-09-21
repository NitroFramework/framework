<?php

namespace Nitro\Container;

use Nitro\Container\Contracts\ClassResolver;
use Nitro\Container\Contracts\ContainerInterface;

/**
 * The container, seen through {@see ClassResolver}.
 *
 * A provider builds one of these instead of handing a class the container
 * itself. Nothing is added — resolution is still the container's — but the
 * receiving class is typed against one method rather than twenty-two.
 *
 * The container cannot implement the interface directly: its own resolve()
 * takes a second parameter and returns mixed, which no covariant signature
 * can narrow to object.
 */
final class ContainerClassResolver implements ClassResolver
{
    public function __construct(private ContainerInterface $container) {}

    /**
     * @template TClass of object
     *
     * @param  class-string<TClass> $class
     * @return TClass
     */
    public function resolve(string $class): object
    {
        return $this->container->resolve($class);
    }
}
