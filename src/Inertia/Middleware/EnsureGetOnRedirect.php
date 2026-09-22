<?php

namespace Nitro\Inertia\Middleware;

use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Inertia\ResponseFactory;
use Nitro\Inertia\Support\Header;

/**
 * Turns a 302 after a write into a 303.
 *
 * A browser repeats the original method when following a 302, so a redirect
 * after a PUT, PATCH or DELETE asks for the target with that method — which is
 * almost never routed. 303 tells it to use GET.
 *
 * {@see \Nitro\Inertia\Middleware} already does this for requests that pass
 * through it; this is the same rule as a standalone, for a route that needs it
 * without the rest.
 */
class EnsureGetOnRedirect
{
    public function handle(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_REDIRECT
            && $request->header(Header::INERTIA)
            && in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)
        ) {
            $response->setStatusCode(ResponseFactory::STATUS_SEE_OTHER);
        }

        return $response;
    }
}
