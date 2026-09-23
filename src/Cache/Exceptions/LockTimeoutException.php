<?php

namespace Nitro\Cache\Exceptions;

use RuntimeException;

/**
 * A blocking wait for a lock ran out before it came free.
 *
 * Distinct from a failed acquire, which is an ordinary false.
 */
class LockTimeoutException extends RuntimeException
{
}
