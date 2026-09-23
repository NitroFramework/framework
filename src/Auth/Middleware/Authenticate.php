<?php

namespace Nitro\Auth\Middleware;

use Nitro\Auth\Contracts\StatefulGuard as Guard;
use Nitro\Auth\Exceptions\AuthenticationException;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Redirects unauthenticated requests to the login route.
 *
 * Authenticated requests pass through; for everyone else the intended URL is
 * remembered (so login can return the user there) before redirecting to login.
 */
class Authenticate
{
    /** @var (callable(Request): ?string)|null */
    protected static $redirectToCallback = null;

    public function __construct(
        protected Guard $auth,
        protected ConfigRepository $config,
    ) {}

    /**
     * Allow authenticated requests to continue; otherwise reject them in the
     * shape the client can actually use.
     *
     * A browser gets the login redirect, with the target URL remembered so it
     * lands back where it was going. An API client — anything sending
     * `Accept: application/json` or an XHR header — gets a 401 instead: sending
     * it a 302 to an HTML login form tells it nothing, and a fetch() following
     * that redirect ends up parsing a login page as its response.
     */
    public function handle(Request $request, callable $next, string ...$guards): Response
    {
        $this->authenticate($request, $guards);

        return $next($request);
    }

    /**
     * Let the request through, or refuse it.
     *
     * The guard names a route asked for are carried into the failure rather
     * than checked against a registry: this framework binds one guard, so
     * there is nothing to select between — but the names are what an exception
     * handler needs in order to say which login a client was refused by.
     *
     * @param  array<int, string> $guards
     * @throws AuthenticationException
     */
    protected function authenticate(Request $request, array $guards): void
    {
        if ($this->auth->check()) {
            return;
        }

        $this->unauthenticated($request, $guards);
    }

    /**
     * Refuse the request.
     *
     * Throwing rather than returning is what lets one place decide the shape
     * of the refusal. A browser should be redirected to a login page with its
     * destination remembered; a JSON client should get a 401, because a fetch()
     * that follows a 302 ends up parsing a login form as its response. Both
     * choices live in the handler, and this only reports what happened.
     *
     * @param  array<int, string> $guards
     * @throws AuthenticationException
     */
    protected function unauthenticated(Request $request, array $guards): never
    {
        // Remembered here, not in the handler: by the time an exception is
        // rendered the request that was interrupted is no longer the subject,
        // and this is the last point that knows where the user was going.
        $this->auth->setIntendedUrl($request->path());

        throw new AuthenticationException(
            'Unauthenticated.',
            $guards,
            $request->expectsJson() ? null : $this->redirectTo($request),
        );
    }

    /** Where a browser should be sent. */
    protected function redirectTo(Request $request): ?string
    {
        if (static::$redirectToCallback !== null) {
            return (static::$redirectToCallback)($request);
        }

        $login = $this->config->get('auth.redirects.login');

        return is_string($login) ? $login : null;
    }

    /**
     * Decide the redirect from the request instead of from configuration.
     *
     * The case for it is an application with more than one login — a customer
     * area and an admin area — where the right page depends on what was being
     * asked for.
     */
    public static function redirectUsing(?callable $redirectToCallback): void
    {
        static::$redirectToCallback = $redirectToCallback;
    }
}
