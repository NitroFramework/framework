<?php

namespace Nitro\Auth\Middleware;

use Nitro\Auth\Contracts\Guard;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Session\Contracts\SessionInterface;

/**
 * Guards sensitive areas behind a recent password confirmation. If the user
 * hasn't confirmed within the timeout window, stashes the intended URL and
 * sends them to the confirm-password screen.
 */
class RequirePassword
{
    public function __construct(
        protected Guard $auth,
        protected ConfigRepository $config,
        protected SessionInterface $session,
    ) {}

    /**
     * Allow the request through when the password was confirmed within the
     * timeout window; otherwise remember the target URL and redirect to the
     * confirm-password screen.
     */
    public function handle(Request $request, callable $next): Response
    {
        $confirmedAt = (int) $this->session->get('auth.password_confirmed_at', 0);
        $timeout     = (int) $this->config->get('auth.password_timeout');

        if (time() - $confirmedAt > $timeout) {
            $this->auth->setIntendedUrl($request->path());

            return Response::redirect(
                $this->config->get('auth.redirects.password_confirm'),
                302,
            );
        }

        return $next($request);
    }
}
