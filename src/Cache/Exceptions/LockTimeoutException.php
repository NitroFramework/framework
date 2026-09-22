<?php

namespace Nitro\Cache\Exceptions;

use RuntimeException;

/**
 * A blocking wait for a lock ran out before it came free.
 *
 * Distinct from a failed acquire, which is an ordinary false: this says the
 * caller was prepared to wait and the wait was not enough, which is usually
 * worth reporting rather than retrying blindly.
 */
class LockTimeoutException extends RuntimeException
{
}
