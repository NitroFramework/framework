<?php

namespace Nitro\Session\Middleware;

use Nitro\Auth\Contracts\StatefulGuard as Guard;
use Nitro\Auth\Exceptions\AuthenticationException;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Logs a user out everywhere once their password changes.
 *
 * The password hash is recorded at login and compared on every request,
 * so changing it invalidates every other session.
 */
class AuthenticateSession
{
    /** Session key holding the hash the session was established with. */
    protected const PASSWORD_HASH = 'password_hash';

    /** @var (callable(Request): ?string)|null */
    protected static $redirectToCallback = null;

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
        $this->guard()->logout();

        $request->session()->flush();

        /*
         * Carries the redirect, so the handler that turns an ordinary auth
         * failure into a login page turns this one into it too.
         */
        throw new AuthenticationException(
            'Unauthenticated.',
            [],
            $request->expectsJson() ? null : $this->redirectTo($request),
        );
    }

    /**
     * The guard this middleware checks.
     *
     * An override point rather than a direct property read, so a subclass can
     * name a different one without reimplementing the hash comparison.
     */
    protected function guard(): Guard
    {
        return $this->auth;
    }

    /** Where a browser should be sent once the session is invalidated. */
    protected function redirectTo(Request $request): ?string
    {
        if (static::$redirectToCallback !== null) {
            return (static::$redirectToCallback)($request);
        }

        return null;
    }

    /**
     * Decide that redirect from the request.
     *
     * Separate from {@see \Nitro\Auth\Middleware\Authenticate::redirectUsing()}
     * because the two failures differ: this one means a session that was valid
     * has been invalidated elsewhere, which an application may want to say
     * something about.
     */
    public static function redirectUsing(?callable $redirectToCallback): void
    {
        static::$redirectToCallback = $redirectToCallback;
    }
}
