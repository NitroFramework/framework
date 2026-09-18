<?php

namespace Nitro\Session;

use Exception;

/**
 * Thrown when a request's CSRF token is missing or does not match the session's.
 */
class TokenMismatchException extends Exception
{
    //
}
