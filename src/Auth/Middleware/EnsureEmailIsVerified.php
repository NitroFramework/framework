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
    public function handle(Request $request, callable $next): Response
    {
        $user = $this->auth->user();

        if ($user instanceof MustVerifyEmail && !$user->hasVerifiedEmail()) {
            return Response::redirect(
                $this->config->get('auth.redirects.verification'),
                302,
            );
        }

        return $next($request);
    }
}
