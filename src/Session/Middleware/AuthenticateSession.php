<?php

namespace Nitro\Session\Middleware;

use Nitro\Auth\Contracts\Guard;
use Nitro\Auth\Exceptions\AuthenticationException;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Logs a user out everywhere once their password changes.
 *
 * The password hash is recorded in the session at login and compared on every
 * request. Changing the password changes the hash, so every other session
 * stops matching and is invalidated — which is what makes "log out my other
 * devices" actually take effect.
 */
class AuthenticateSession
{
    /** Session key holding the hash the session was established with. */
    protected const PASSWORD_HASH = 'password_hash';

    public function __construct(
        protected Guard $auth,
    ) {}

    /**
     * Invalidate the session when it no longer matches the user's password.
     *
     * @throws AuthenticationException When the recorded hash has gone stale.
     */
    public function handle(Request $request, callable $next): Response
    {
        $user = $this->auth->user();

        if (! $request->hasSession() || ! $user || ! $user->getAuthPassword()) {
            return $next($request);
        }

        $session = $request->session();

        if (! $session->has(self::PASSWORD_HASH)) {
            $this->storePasswordHashInSession($request);
        }

        if (! $this->validatePasswordHash($user->getAuthPassword(), $session->get(self::PASSWORD_HASH))) {
            $this->logout($request);
        }

        $response = $next($request);

        if ($this->auth->user() !== null) {
            $this->storePasswordHashInSession($request);
        }

        return $response;
    }

    /**
     * Record the user's current password hash in the session.
     */
    protected function storePasswordHashInSession(Request $request): void
    {
        if (! $user = $this->auth->user()) {
            return;
        }

        $request->session()->put(self::PASSWORD_HASH, $user->getAuthPassword());
    }

    /**
     * Compare a password hash with the one the session was established with.
     */
    protected function validatePasswordHash(string $passwordHash, mixed $storedValue): bool
    {
        return is_string($storedValue) && hash_equals($passwordHash, $storedValue);
    }

    /**
     * Drop the session and reject the request.
     *
     * @throws AuthenticationException
     */
    protected function logout(Request $request): void
    {
        $this->auth->logout();

        $request->session()->flush();

        throw new AuthenticationException('Unauthenticated.');
    }
}
