<?php

namespace Nitro\Routing\Exceptions;

use InvalidArgumentException;
use Nitro\Routing\Route;

/**
 * A route was named correctly but not given everything its path needs.
 *
 * Separate from {@see RouteNotFoundException} because the two are fixed
 * differently: one is a wrong name, the other a missing argument. The message
 * names the parameters that were absent, since "missing parameters" on a route
 * with four of them says nothing about which.
 *
 * Extends InvalidArgumentException, which is what route() threw before this
 * existed, so code already catching that keeps working.
 */
class UrlGenerationException extends InvalidArgumentException
{
    /**
     * @param array<int, string> $missing Placeholder names nothing was given for.
     */
    public static function forMissingParameters(string $name, array $missing = []): static
    {
        $message = "Missing required parameter" . (count($missing) === 1 ? '' : 's')
            . " for route [{$name}]";

        return new static($missing === []
            ? "{$message}."
            : "{$message}: " . implode(', ', $missing) . '.');
    }

    /** @param array<int, string> $missing */
    public static function forRoute(Route $route, array $missing = []): static
    {
        return static::forMissingParameters($route->getName() ?? $route->getPath(), $missing);
    }
}
