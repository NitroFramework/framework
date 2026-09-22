<?php

namespace Nitro\Auth\Middleware;

use Nitro\Auth\Contracts\Guard;
use Nitro\Auth\Contracts\MustVerifyEmail;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Blocks users whose email isn't verified yet, sending them to the verification
 * notice. Users that don't implement MustVerifyEmail pass through untouched.
 */
class EnsureEmailIsVerified
{
    public function __construct(
        protected Guard $auth,
        protected ConfigRepository $config,
    ) {}

    /**
     * Allow verified (or non-verifiable) users through; redirect an unverified
     * user that implements MustVerifyEmail to the verification notice.
     */
    public function handle(Request $request, callable $next, ?string $redirectTo = null): Response
    {
        $user = $this->auth->user();

        /*
         * A guest fails this too. In a stack that puts `auth` first there is
         * no such request, but the check is what makes this middleware safe on
         * its own — otherwise a route protected only by this one admits
         * everybody who is not signed in.
         */
        if ($user === null || ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail())) {
            // A JSON client cannot act on a redirect to a verification page;
            // 403 says what is wrong in terms it can report.
            return $request->expectsJson()
                ? Response::json(['message' => 'Your email address is not verified.'], 403)
                : Response::redirect(
                    $redirectTo ?? (string) $this->config->get('auth.redirects.verification'),
                    302,
                );
        }

        return $next($request);
    }
}
