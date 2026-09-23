<?php

namespace Nitro\Routing\Exceptions;

use InvalidArgumentException;

/**
 * A URL was asked for by a name no route carries.
 *
 * A route name is not runtime data — the table is fixed when the application
 * boots — so this is always a programming error, and the only question is who
 * finds out. Its own type so a caller can tell it from a route that exists but
 * was not given everything it needs.
 *
 * Extends InvalidArgumentException, which is what route() threw before this
 * existed, so code already catching that keeps working.
 */
class RouteNotFoundException extends InvalidArgumentException
{
    public static function forName(string $name): static
    {
        return new static("Route [{$name}] not defined.");
    }
}
