<?php

namespace Nitro\Auth\Middleware;

use Nitro\Auth\Contracts\Guard;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Session\Contracts\Session;

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
        protected Session $session,
    ) {}

    /**
     * Allow the request through when the password was confirmed within the
     * timeout window; otherwise remember the target URL and redirect to the
     * confirm-password screen.
     */
    public function handle(
        Request $request,
        callable $next,
        ?string $redirectTo = null,
        ?int $passwordTimeoutSeconds = null,
    ): Response {
        if ($this->shouldConfirmPassword($request, $passwordTimeoutSeconds)) {
            /*
             * 423 Locked, not a redirect: an API client sent to an HTML
             * confirmation page has no way to act on it, and 423 is the status
             * that means "you may have this once you prove it is still you".
             */
            if ($request->expectsJson()) {
                return Response::json(['message' => 'Password confirmation required.'], 423);
            }

            $this->auth->setIntendedUrl($request->path());

            return Response::redirect(
                $redirectTo ?? (string) $this->config->get('auth.redirects.password_confirm'),
                302,
            );
        }

        return $next($request);
    }

    /**
     * Whether the confirmation has gone stale.
     *
     * An override point because the window is a policy decision: an
     * administrative action may want a shorter one than the application's
     * default, without a second middleware to express it.
     */
    protected function shouldConfirmPassword(Request $request, ?int $passwordTimeoutSeconds = null): bool
    {
        $confirmedAt = (int) $this->session->get('auth.password_confirmed_at', 0);

        return (time() - $confirmedAt) > ($passwordTimeoutSeconds ?? (int) $this->config->get('auth.password_timeout'));
    }
}
