<?php

namespace Nitro\Session\Middleware;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Session\NativeSession;

/**
 * Starts the session for routes whose middleware group includes it.
 *
 * A session is a per-route concern, not a global one. Only routes in a group
 * carrying this middleware (the 'web' group) load and persist session state;
 * stateless JSON routes and cacheable asset routes never touch a session file.
 * Previously this ran from a global kernel hook that fired before routing, so
 * it could not know the matched route's group and every request paid for a
 * session file read + write whether it used one or not.
 *
 * Only the *start* half lives here. The matching Set-Cookie and save() run from
 * SessionServiceProvider's response-ready and terminating hooks, for two
 * reasons that both rule out a post-$next block in this class:
 *
 *  - They must still run when an exception unwinds the middleware stack. A
 *    validation failure throws HttpResponseException to short-circuit with
 *    errors flashed to the session; if the save sat after $next it would be
 *    skipped and those errors silently lost.
 *  - save() belongs after the response is flushed (Kernel::terminate runs
 *    post-send), keeping the write off the request's critical path.
 *
 * Those hooks no-op unless this middleware actually started a session, so the
 * lean path stays lean.
 */
class StartSession
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    /**
     * Adopt the client's session id, then start the session so downstream
     * middleware (VerifyCsrfToken) and the route handler see live state.
     */
    public function handle(Request $request, callable $next): Response
    {
        $session = $this->container->createOrResolve('session');

        // The native driver reads PHP's own cookie inside session_start(), so
        // only the self-managed (file/array) drivers need the id seeded here.
        if (! $session instanceof NativeSession) {
            $id = $request->cookie($session->getName());
            if (is_string($id) && $id !== '') {
                $session->setId($id);
            }
        }

        $session->start();

        return $next($request);
    }
}
