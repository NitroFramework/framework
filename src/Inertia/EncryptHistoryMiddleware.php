<?php

namespace Nitro\Inertia;

use Nitro\Facades\Inertia;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Marks every page behind it for history encryption.
 *
 * The client keeps visited pages in browser history so a back navigation is
 * instant; for a page holding anything sensitive that is a copy sitting in the
 * browser's store. Applied as route middleware, this asks the client to
 * encrypt those entries.
 *
 * The base class exists so an application can extend it to add its own
 * condition — encrypt only for authenticated users, say — while
 * {@see \Nitro\Inertia\Middleware\EncryptHistory} stays the plain one to name
 * in a route.
 */
class EncryptHistoryMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        Inertia::encryptHistory();

        return $next($request);
    }
}
