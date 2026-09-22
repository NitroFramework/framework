<?php

namespace Nitro\Http\Middleware;

use Nitro\Foundation\MaintenanceMode;
use Nitro\Http\Cookie;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Turns the down file into an actual 503.
 *
 * MaintenanceMode could already be activated, read, given a retry window and a
 * bypass secret — and nothing consulted it, so `nitro down` wrote a file and the
 * site carried on serving. A maintenance mode nobody checks is not a feature
 * with a missing command; it is a switch wired to nothing.
 *
 * Sits in the global stack so it covers a 404 as much as a hit: a URL that does
 * not exist should still say the site is down rather than report on the routing
 * table of an application that is mid-deploy.
 */
class PreventRequestsDuringMaintenance
{
    use Concerns\ExcludesPaths;

    /** Cookie carrying a bypass, so the secret is used once rather than pasted into every URL. */
    public const BYPASS_COOKIE = 'nitro_maintenance_bypass';

    /**
     * URIs that answer normally while the application is down.
     *
     * A health check is the case that matters: a load balancer that cannot
     * reach one takes the instance out of rotation, and then the deploy that
     * put the site into maintenance has nothing to come back to.
     *
     * @var array<int, string>
     */
    protected array $except = [];

    public function __construct(protected MaintenanceMode $maintenance) {}

    public function handle(Request $request, callable $next): Response
    {
        if (! $this->maintenance->active()) {
            return $next($request);
        }

        // Presenting the secret as a path sets a cookie and sends the visitor to
        // the root, so the secret leaves the URL bar — and the browser history,
        // and any referrer header — immediately.
        $secret = trim($request->path(), '/');

        if ($secret !== '' && $this->maintenance->bypassedBy($secret)) {
            return Response::redirect('/')
                ->withCookie(new Cookie(self::BYPASS_COOKIE, $secret, 0, '/', null, $request->secure(), true));
        }

        if ($this->hasValidBypassCookie($request) || $this->isExcepted($request)) {
            return $next($request);
        }

        return $this->downResponse();
    }

    /** Whether this request carries a bypass cookie the current secret accepts. */
    protected function hasValidBypassCookie(Request $request): bool
    {
        $cookie = $request->cookie(self::BYPASS_COOKIE);

        return is_string($cookie) && $this->maintenance->bypassedBy($cookie);
    }

    /**
     * Whether the URI is one that must keep answering while down.
     *
     * Matched through the shared trait rather than here, so an exemption
     * written for this middleware means the same thing as one written for
     * CSRF — including the leading slash, which is otherwise the kind of
     * detail two implementations quietly disagree about.
     */
    protected function isExcepted(Request $request): bool
    {
        return $this->inExceptArray($request);
    }

    /**
     * The 503.
     *
     * Retry-After is the part worth getting right: without it a crawler decides
     * for itself when to come back, and a 503 with no hint is how a deploy
     * window turns into deindexed pages.
     */
    protected function downResponse(): Response
    {
        $data = $this->maintenance->data();
        $retry = $this->maintenance->retryAfter();
        $message = (string) ($data['message'] ?? 'Service Unavailable');

        $response = new Response(
            '<!DOCTYPE html><html><head><title>Service Unavailable</title></head>'
            . '<body><h1>' . htmlspecialchars($message, ENT_QUOTES) . '</h1></body></html>',
            503,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );

        if ($retry !== null) {
            $response->header('Retry-After', (string) $retry);
        }

        return $response;
    }
}
