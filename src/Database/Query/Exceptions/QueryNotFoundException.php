<?php

namespace Nitro\Database\Query\Exceptions;

use RuntimeException;

/** Thrown when query('name') references a named query that was never registered. */
class QueryNotFoundException extends RuntimeException
{
}
