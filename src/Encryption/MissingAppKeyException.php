<?php

namespace Nitro\Encryption;

use RuntimeException;

/** Thrown when no application key is configured (config('app.key') empty). */
class MissingAppKeyException extends RuntimeException
{
    public function __construct(string $message = 'No application key has been specified. Run "php nitro key:generate".')
    {
        parent::__construct($message);
    }
}
