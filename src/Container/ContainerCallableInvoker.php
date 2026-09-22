<?php

namespace Nitro\Container;

use Closure;
use Illuminate\Container\Container as IlluminateContainer;
use Nitro\Container\Contracts\CallableInvoker;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The container, seen through {@see CallableInvoker}.
 *
 * Construction is still the container's; how a callable's parameters are
 * filled is this class's, because the rules are the framework's rather than
 * any container's. A route hands its segments over positionally as well as by
 * name, and a typed parameter whose name matches a segment is a model to look
 * up rather than a dependency to build. Neither is something a container is
 * expected to know, so neither is asked of one here.
 *
 * Pairs with {@see ContainerClassResolver} for a class that both builds and
 * calls.
 */
final class ContainerCallableInvoker implements CallableInvoker
{
    /**
     * Reflection for a method, keyed by "Class::method".
     *
     * The reflector is instance-independent, so one entry serves every later
     * invocation and the controller-dispatch path stops reflecting after the
     * first hit.
     *
     * @var array<string, ReflectionMethod>
     */
    private array $methods = [];

    /**
     * Resolver consulted for route-model binding, called as
     * ($type, $value, $name) and returning PARAM_UNRESOLVED to decline.
     */
    private ?Closure $parameterBinder = null;

    public function __construct(private IlluminateContainer $container) {}

    /**
     * Register the parameter binder used for route-model binding.
     *
     * The declared name travels with the value because a route may bind a
     * parameter by a column of its own choosing — "{post:slug}" — and only the
     * name says which parameter that applies to.
     */
    public function bindParametersUsing(Closure $resolver): void
    {
        $this->parameterBinder = $resolver;
    }

    /**
     * Invoke a callable, filling its parameters.
     *
     * Takes every form a call is written in: a closure or function name, an
     * [object, method] or [class, method] pair, an invokable object, and the
     * "Class@method" and "Class::method" strings a route or a config file
     * names an action by. A method with a binding registered through the
     * container's bindMethod() runs that in its place.
     *
     * @param array<array-key, mixed> $parameters
     */
    public function call(callable|string|array $callable, array $parameters = []): mixed
    {
        if ($callable instanceof Closure) {
            return $this->callFunction($callable, $parameters);
        }

        if (is_string($callable)) {
            if (str_contains($callable, '@')) {
                [$class, $method] = explode('@', $callable, 2);
                $callable = [$this->container->make($class), $method];
            } elseif (str_contains($callable, '::')) {
                $callable = explode('::', $callable, 2);
            } else {
                return $this->callFunction($callable, $parameters);
            }
        } elseif (is_object($callable)) {
            $callable = [$callable, '__invoke'];
        }

        [$object, $method] = $callable;

        $binding = (is_object($object) ? $object::class : $object) . '@' . $method;

        if ($this->container->hasMethodBinding($binding)) {
            return $this->container->callMethodBinding($binding, $object);
        }

        $reflector = $this->method($object, $method);

        if (! is_object($object) && ! $reflector->isStatic()) {
            $object = $this->container->make($object);
        }

        return $reflector->invokeArgs(
            is_object($object) ? $object : null,
            $this->bind($reflector->getParameters(), $parameters)
        );
    }

    /**
     * The argument list a method would be called with, without calling it.
     *
     * For a caller that has to own the invocation itself — a controller
     * dispatching through callAction() — but still wants the typed
     * dependencies and route parameters bound the way call() binds them.
     *
     * @param  array<array-key, mixed> $parameters
     * @return array<int, mixed>
     */
    public function arguments(object|string $object, string $method, array $parameters = []): array
    {
        return $this->bind($this->method($object, $method)->getParameters(), $parameters);
    }

    /** @param array<array-key, mixed> $parameters */
    private function callFunction(Closure|string $function, array $parameters): mixed
    {
        $reflector = new ReflectionFunction($function);

        return $reflector->invokeArgs($this->bind($reflector->getParameters(), $parameters));
    }

    /** The cached reflector for a method, keyed by "Class::method". */
    private function method(object|string $object, string $method): ReflectionMethod
    {
        $key = (is_object($object) ? $object::class : $object) . '::' . $method;

        return $this->methods[$key] ??= new ReflectionMethod($object, $method);
    }

    /**
     * Work out what to pass each parameter.
     *
     * A class-typed parameter is a dependency to be built, not a route
     * segment — so a positional value is only handed to one when the binder
     * can turn it into that class. Otherwise the position is left for the next
     * scalar parameter and the type is resolved from the container. Without
     * that distinction, __invoke(string $code, Renderer $r) gets the route's
     * second value in $r and dies on a type error, which is a confusing way to
     * learn that a controller may not ask for anything after a route parameter.
     *
     * @param  array<int, ReflectionParameter> $parameters
     * @param  array<array-key, mixed>         $overrides
     * @return array<int, mixed>
     */
    private function bind(array $parameters, array $overrides): array
    {
        $arguments = [];
        $position = 0;

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            $class = ($type instanceof ReflectionNamedType && ! $type->isBuiltin())
                ? $type->getName()
                : null;

            if ($parameter->isVariadic()) {
                array_push($arguments, ...$this->variadic($name, $position, $overrides));
                break;
            }

            if (
                $class !== null
                && $this->parameterBinder !== null
                && array_key_exists($name, $overrides)
                && is_scalar($overrides[$name])
            ) {
                $bound = ($this->parameterBinder)($class, $overrides[$name], $name);

                if ($bound !== self::PARAM_UNRESOLVED) {
                    $arguments[] = $bound;
                    continue;
                }
            }

            if (array_key_exists($name, $overrides)) {
                $arguments[] = $overrides[$name];
                continue;
            }

            if (array_key_exists($position, $overrides)) {
                $value = $overrides[$position];

                if ($class === null || $value instanceof $class) {
                    $arguments[] = $value;
                    $position++;
                    continue;
                }

                if ($this->parameterBinder !== null && is_scalar($value)) {
                    $bound = ($this->parameterBinder)($class, $value, $name);

                    if ($bound !== self::PARAM_UNRESOLVED) {
                        $arguments[] = $bound;
                        $position++;
                        continue;
                    }
                }
            }

            if ($class !== null && $class !== 'Closure') {
                /*
                 * Optional means optional: a class-typed parameter with a
                 * default takes it when the type cannot be built. Only the
                 * container's own failures are caught, so an exception thrown
                 * inside a constructor is never hidden by a default.
                 */
                try {
                    $arguments[] = $this->container->make($class);
                    continue;
                } catch (\Illuminate\Contracts\Container\BindingResolutionException $exception) {
                    if (! $parameter->isDefaultValueAvailable()) {
                        throw $exception;
                    }
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            $declaring = $parameter->getDeclaringClass();

            throw new \Illuminate\Contracts\Container\BindingResolutionException(
                "Cannot resolve parameter [\${$name}]"
                . ($declaring ? " in [{$declaring->getName()}]" : '')
                . ' — no value given and no rule to build one.'
            );
        }

        return $arguments;
    }

    /**
     * What a variadic parameter receives: the named override spread out, else
     * every positional override not yet consumed, else nothing at all.
     *
     * @param  array<array-key, mixed> $overrides
     * @return array<int, mixed>
     */
    private function variadic(string $name, int $position, array $overrides): array
    {
        if (array_key_exists($name, $overrides)) {
            return array_values((array) $overrides[$name]);
        }

        $values = [];

        while (array_key_exists($position, $overrides)) {
            $values[] = $overrides[$position++];
        }

        return $values;
    }
}
