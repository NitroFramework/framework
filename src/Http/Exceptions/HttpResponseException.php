<?php

namespace Nitro\Http\Exceptions;

use Nitro\Http\Response;
use RuntimeException;

/**
 * Carries a ready-made Response out of deep call stacks.
 *
 * Lets helpers like request()->validate() short-circuit the request from
 * anywhere — they throw this with the response they want sent, and the kernel
 * unwraps it instead of running the normal exception renderer. This is how
 * "validate, else redirect back with errors" works without the controller
 * threading a return value back up.
 */
class HttpResponseException extends RuntimeException
{
    public function __construct(protected Response $response)
    {
        parent::__construct('HTTP response exception');
    }

    public function getResponse(): Response
    {
        return $this->response;
    }
}
