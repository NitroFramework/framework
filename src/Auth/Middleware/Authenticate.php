<?php

namespace Nitro\Auth\Middleware;

use Nitro\Auth\Contracts\Guard;
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
    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return Response::json(['message' => 'Unauthenticated.'], 401);
        }

        $this->auth->setIntendedUrl($request->path());

        return Response::redirect(
            $this->config->get('auth.redirects.login'),
            302,
        );
    }
}
