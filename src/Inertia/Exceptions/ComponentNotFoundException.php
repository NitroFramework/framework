<?php

namespace Nitro\Inertia\Exceptions;

use InvalidArgumentException;

/**
 * A page component the client has no module for.
 *
 * Raised on the server, where the component name is still a value that can be
 * reported, rather than leaving the client to fail on a lookup that returns
 * nothing.
 */
class ComponentNotFoundException extends InvalidArgumentException
{
}
