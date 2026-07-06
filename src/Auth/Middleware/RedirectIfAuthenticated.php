<?php

namespace Nitro\Auth\Middleware;

use Nitro\Auth\Contracts\Guard;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Redirects already-authenticated users away from guest-only routes such as
 * login and register, sending them to the dashboard.
 */
class RedirectIfAuthenticated
{
    public function __construct(
        protected Guard $auth,
        protected ConfigRepository $config,
    ) {}

    /**
     * Let guests continue; redirect authenticated users to the dashboard.
     */
    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth->guest()) {
            return $next($request);
        }

        return Response::redirect(
            $this->config->get('auth.redirects.dashboard'),
            302,
        );
    }
}
