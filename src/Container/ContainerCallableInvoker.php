<?php

namespace Nitro\Container;

use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Container\Contracts\ContainerInterface;

/**
 * The container, seen through {@see CallableInvoker}.
 *
 * Invocation is still the container's; the receiving class is typed against
 * two methods rather than twenty-two. Pairs with {@see ContainerClassResolver}
 * for a class that both builds and calls.
 */
final class ContainerCallableInvoker implements CallableInvoker
{
    public function __construct(private ContainerInterface $container) {}

    /** @param array<array-key, mixed> $parameters */
    public function call(callable $callable, array $parameters = []): mixed
    {
        return $this->container->call($callable, $parameters);
    }

    /**
     * @param  array<array-key, mixed> $parameters
     * @return array<int, mixed>
     */
    public function arguments(object|string $object, string $method, array $parameters = []): array
    {
        return $this->container->arguments($object, $method, $parameters);
    }
}
