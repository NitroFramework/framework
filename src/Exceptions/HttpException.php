<?php

namespace Nitro\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An HTTP exception carrying a status code, rendered as the matching response.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        private int $statusCode,
        string $message = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
