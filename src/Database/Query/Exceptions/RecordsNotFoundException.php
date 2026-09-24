<?php

namespace Nitro\Database\Query\Exceptions;

use RuntimeException;

/**
 * A query that had to return a row returned none.
 *
 * Thrown by firstOrFail(), so the absence surfaces where it happened rather
 * than as a call on null further down. The model layer throws its own
 * {@see \Nitro\Database\Model\ModelNotFoundException} for the same reason.
 */
class RecordsNotFoundException extends RuntimeException
{
}
