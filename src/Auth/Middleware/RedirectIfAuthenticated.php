<?php

namespace Nitro\Auth\Middleware;

use Nitro\Auth\Contracts\StatefulGuard as Guard;
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
    public function handle(Request $request, callable $next, string ...$guards): Response
    {
        if ($this->auth->guest()) {
            return $next($request);
        }

        return Response::redirect($this->redirectTo($request), 302);
    }

    /**
     * Where an already-signed-in visitor is sent.
     *
     * An override point so an application can decide from the request — an
     * admin returning to an admin dashboard rather than the customer one —
     * without a second middleware to say it.
     */
    protected function redirectTo(Request $request): string
    {
        return $this->defaultRedirectUri();
    }

    protected function defaultRedirectUri(): string
    {
        return (string) $this->config->get('auth.redirects.dashboard');
    }
}
