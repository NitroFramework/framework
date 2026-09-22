<?php

namespace Nitro\Http\Exceptions;

use Nitro\Exceptions\HttpException;

/**
 * A request body larger than PHP was configured to accept.
 *
 * Worth its own type because of how the failure presents: PHP discards the
 * body before the application sees it, so a too-large upload arrives looking
 * like an empty form. Without this the user is told their fields are
 * required, which is both wrong and impossible to act on.
 */
class PostTooLargeException extends HttpException
{
    public function __construct(string $message = 'The POST data is too large.', ?\Throwable $previous = null)
    {
        parent::__construct(413, $message, $previous);
    }
}
