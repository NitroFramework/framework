<?php

namespace Nitro\Routing\Contracts;

/**
 * A router that can say which verbs a path does answer.
 *
 * Without this a request to a known path with the wrong verb is
 * indistinguishable from a request to nothing at all, and both come back 404.
 * RFC 9110 calls that case 405, and requires an Allow header naming the verbs
 * that would have worked — which is also what makes a CORS preflight and a
 * plain `curl -X OPTIONS` useful.
 *
 * Optional, and separate from {@see RouterInterface}, for the same reason
 * {@see ExtendableRouter} is: a router that cannot answer the question is
 * simply not asked, and the framework falls back to 404.
 */
interface ReportsAllowedMethods
{
    /**
     * The HTTP verbs that have a route registered at this path.
     *
     * @param  string $path The request path, as {@see \Nitro\Http\Request::path()} gives it.
     * @return array<int, string> Uppercase verbs, empty when the path is unknown.
     */
    public function allowedMethods(string $path): array;
}
