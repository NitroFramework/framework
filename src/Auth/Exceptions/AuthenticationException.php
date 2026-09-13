<?php

namespace Nitro\Auth\Exceptions;

use RuntimeException;

/**
 * Nobody is signed in, and this needed somebody to be.
 *
 * The middleware can refuse a request on its own, but only the middleware —
 * and "who is this?" gets asked a long way from there: in an action, in a
 * Livewire component, inside a service two calls down. Without an exception
 * those places can only abort(401), which throws away the distinction that
 * matters: a browser should be sent to the login page with its destination
 * remembered, and an API client should get a 401, because a fetch() that
 * follows a 302 ends up parsing a login form as its response.
 *
 * Carrying the guards and the redirect means the handler can make that choice
 * once, in one place, rather than every caller making it again.
 */
class AuthenticationException extends RuntimeException
{
    /**
     * @param  array<int, string>  $guards  the guards that were tried
     * @param  string|null  $redirectTo  where a browser should be sent
     */
    public function __construct(
        string $message = 'Unauthenticated.',
        protected array $guards = [],
        protected ?string $redirectTo = null,
    ) {
        parent::__construct($message);
    }

    /** @return array<int, string> */
    public function guards(): array
    {
        return $this->guards;
    }

    public function redirectTo(): ?string
    {
        return $this->redirectTo;
    }
}
