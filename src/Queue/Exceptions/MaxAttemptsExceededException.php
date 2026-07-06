<?php

namespace Nitro\Queue\Exceptions;

use RuntimeException;

/**
 * Thrown when the Worker observes that a job has already hit its $tries
 * cap (typically because a prior worker crashed mid-handle and the row
 * reservation expired). The Worker catches this and routes the job to
 * the failed store rather than running handle() one more time on a
 * job the user already considers dead.
 */
class MaxAttemptsExceededException extends RuntimeException
{
}
