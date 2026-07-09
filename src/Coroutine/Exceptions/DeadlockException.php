<?php

namespace Nitro\Coroutine\Exceptions;

use RuntimeException;

/**
 * Thrown when every live coroutine is parked (awaiting each other or a channel that
 * will never receive) and there is no pending I/O or timer to ever wake them — the
 * scheduler would otherwise spin forever. A programming error, surfaced eagerly.
 */
class DeadlockException extends RuntimeException
{
}
