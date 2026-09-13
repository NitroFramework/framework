<?php

namespace Nitro\Auth\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Somebody is signed in and still may not do this.
 *
 * Separate from AuthenticationException because the answers differ: signing in
 * would fix the first and will not fix the second, so sending this person to
 * the login page is a loop.
 *
 * The status is settable because 403 is not always the honest answer. Telling
 * somebody they are forbidden from reading an order confirms the order exists,
 * which is why most of this application answers with 404 instead — the
 * existence of another customer's record is not ours to disclose.
 */
class AuthorizationException extends RuntimeException
{
    public function __construct(
        string $message = 'This action is unauthorized.',
        protected int $status = 403,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * The same refusal, answered as though the thing were not there.
     *
     *     throw (new AuthorizationException())->asNotFound();
     */
    public function asNotFound(): static
    {
        $this->status = 404;

        return $this;
    }
}
