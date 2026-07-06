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
     * Allow authenticated requests to continue; otherwise remember the target
     * URL and redirect to the configured login route.
     */
    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        $this->auth->setIntendedUrl($request->path());

        return Response::redirect(
            $this->config->get('auth.redirects.login'),
            302,
        );
    }
}
