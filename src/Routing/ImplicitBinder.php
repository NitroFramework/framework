<?php

namespace Nitro\Routing;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Support\Str;

/**
 * ImplicitRouteBinding::resolveForRoute() driven by a compiled signature map:
 * ['enums' => [[param, class], ...], 'models' => [[param, class], ...]].
 */
final class ImplicitBinder
{
    public static function resolve($container, $route, array $bindings): void
    {
        $parameters = $route->parameters();

        foreach ($bindings['enums'] as [$name, $enumClass]) {
            if (! $parameterName = self::parameterName($name, $parameters)) {
                continue;
            }

            $value = $parameters[$parameterName];

            if ($value === null) {
                continue;
            }

            $enum = $value instanceof $enumClass ? $value : $enumClass::tryFrom((string) $value);

            if ($enum === null) {
                throw new BackedEnumCaseNotFoundException($enumClass, $value);
            }

            $route->setParameter($parameterName, $enum);
        }

        foreach ($bindings['models'] as [$name, $class]) {
            if (! $parameterName = self::parameterName($name, $parameters)) {
                continue;
            }

            $value = $parameters[$parameterName];

            if ($value instanceof UrlRoutable) {
                continue;
            }

            $instance = $container->make($class);
            $parent = $route->parentOfParameter($parameterName);
            $trashed = $route->allowsTrashedBindings() && $instance::isSoftDeletable();

            if ($parent instanceof UrlRoutable &&
                ! $route->preventsScopedBindings() &&
                ($route->enforcesScopedBindings() || array_key_exists($parameterName, $route->bindingFields()))) {
                $method = $trashed ? 'resolveSoftDeletableChildRouteBinding' : 'resolveChildRouteBinding';

                if (! $model = $parent->{$method}($parameterName, $value, $route->bindingFieldFor($parameterName))) {
                    throw (new ModelNotFoundException)->setModel(get_class($instance), [$value]);
                }
            } else {
                $method = $trashed ? 'resolveSoftDeletableRouteBinding' : 'resolveRouteBinding';

                if (! $model = $instance->{$method}($value, $route->bindingFieldFor($parameterName))) {
                    throw (new ModelNotFoundException)->setModel(get_class($instance), [$value]);
                }
            }

            $route->setParameter($parameterName, $model);
        }
    }

    private static function parameterName(string $name, array $parameters): ?string
    {
        if (array_key_exists($name, $parameters)) {
            return $name;
        }

        return array_key_exists($snaked = Str::snake($name), $parameters) ? $snaked : null;
    }
}
