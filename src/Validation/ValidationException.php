<?php

namespace Nitro\Validation;

use RuntimeException;

/**
 * Thrown when validation fails. Carries the error bag and the intended HTTP
 * status (422); app code catches it and reads errors(). The redirect-back / 422
 * JSON response is produced by the exception handler (registered in
 * ExceptionServiceProvider).
 */
class ValidationException extends RuntimeException
{
    public function __construct(
        protected ErrorBag $errors,
        public readonly int $status = 422,
    ) {
        parent::__construct('The given data was invalid.');
    }

    /** The validation error bag. */
    public function errors(): ErrorBag
    {
        return $this->errors;
    }
}
