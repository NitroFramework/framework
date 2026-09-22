<?php

namespace Nitro\Session\Middleware;

use Closure;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Session\Contracts\Session;
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
    /** @param Closure(): Session $session The request's store, fetched per call. */
    public function __construct(
        private Closure $session,
    ) {}

    /**
     * Adopt the client's session id, then start the session so downstream
     * middleware (VerifyCsrfToken) and the route handler see live state.
     */
    public function handle(Request $request, callable $next): Response
    {
        // Called per request, not injected: the session is request-scoped and
        // this middleware is shared for the life of a worker.
        $session = ($this->session)();

        // The native driver reads PHP's own cookie inside session_start(), so
        // only the self-managed (file/array) drivers need the id seeded here.
        if (! $session instanceof NativeSession) {
            $id = $request->cookie($session->getName());
            if (is_string($id) && $id !== '') {
                $session->setId($id);
            }
        }

        $session->start();

        $this->storeCurrentUrl($request, $session);

        return $next($request);
    }

    /**
     * Remember where the user is, so a later redirect can send them back.
     *
     * Without this, back() has only the Referer header to work from — which a
     * browser may omit, and which is stripped on a cross-origin navigation, so
     * a validation failure that should return the user to the form they were
     * filling in sends them to the fallback instead.
     *
     * Only a plain GET is worth remembering. A POST is the action itself, an
     * AJAX call is not where the user is, and a prefetch is a page they have
     * not visited — recording any of those would send them somewhere they were
     * never looking at.
     */
    private function storeCurrentUrl(Request $request, Session $session): void
    {
        if (! $request->isMethod('GET')
            || $request->route() === null
            || $request->ajax()
            || $request->prefetch()) {
            return;
        }

        $session->setPreviousUrl($request->fullUrl());

        if (method_exists($session, 'setPreviousRoute')) {
            $route = $request->route();

            $session->setPreviousRoute(
                (is_object($route) && method_exists($route, 'getName')) ? (string) $route->getName() : ''
            );
        }
    }
}
