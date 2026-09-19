<?php

namespace Nitro\Http\Middleware;

use Closure;
use Nitro\Exceptions\HttpException;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Routing\Contracts\SignsUrls;

/**
 * Refuses a request whose signed URL does not verify.
 *
 * Registered as the 'signed' alias, so a route that hands out a link to
 * somebody with no session — an unsubscribe, a password reset, an emailed
 * confirmation — can require that the link is the one the app issued:
 *
 *     Route::get('/unsubscribe/{user}', …)->middleware('signed');
 *
 * A 403 rather than a 404: the URL exists, it is the signature that does not
 * hold, and saying so is what tells a user their link has expired.
 */
class ValidateSignature
{
    public function __construct(
        protected RouterInterface $router,
    ) {}

    /**
     * A router that cannot verify gets a refusal, never a pass: an application
     * that swapped in a router without signing must not have its signature
     * checks quietly turn into no-ops.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->router instanceof SignsUrls || ! $this->router->hasValidSignature($request)) {
            throw new HttpException(403, 'Invalid or expired signature.');
        }

        return $next($request);
    }
}
