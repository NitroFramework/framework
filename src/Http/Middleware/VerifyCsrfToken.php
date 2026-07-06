<?php

namespace Nitro\Http\Middleware;

use Nitro\Exceptions\HttpException;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Verifies the CSRF token on state-changing (non-read) requests.
 *
 * Read methods (GET/HEAD/OPTIONS) and any URI in the exempt list pass straight
 * through. Everything else must present a token — via the `_token` form field
 * (what `@csrf`/`csrf_field()` emit) or an `X-CSRF-TOKEN` / `X-XSRF-TOKEN`
 * header — that matches the per-session token. Comparison is constant-time.
 *
 * This is the standard-form counterpart to the HTMX layer's own request guard;
 * both read the same session token, so a token minted by `csrf_token()` is
 * valid across both stacks.
 */
class VerifyCsrfToken
{
    /** HTTP methods that never require a token (they must not mutate state). */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * URI paths exempt from verification (e.g. stateless webhooks). Supports a
     * trailing '*' wildcard: 'webhooks/*' matches any path under /webhooks.
     *
     * @param array<int, string> $except
     */
    public function __construct(
        private array $except = [],
    ) {}

    /**
     * Let read-verb, exempt, or correctly-tokened requests through; throw a
     * 419 (token mismatch) otherwise. Throwing — rather than calling abort() —
     * routes the failure through the Kernel's exception handler so it gets the
     * normal status/HTMX/JSON negotiation and response-ready hooks.
     *
     * @throws HttpException 419 when the token is missing or does not match.
     */
    public function handle(Request $request, callable $next): Response
    {
        if ($this->isReading($request) || $this->isExcept($request) || $this->tokensMatch($request)) {
            return $next($request);
        }

        throw new HttpException(419, 'CSRF token mismatch.');
    }

    /** Read verbs are inherently safe and skip verification. */
    private function isReading(Request $request): bool
    {
        return in_array($request->method(), self::READ_METHODS, true);
    }

    /** Whether the request URI matches a configured exemption. */
    private function isExcept(Request $request): bool
    {
        if ($this->except === []) {
            return false;
        }

        $path = trim($request->path(), '/');
        foreach ($this->except as $pattern) {
            $pattern = trim($pattern, '/');
            if ($pattern === $path) {
                return true;
            }
            if (str_ends_with($pattern, '/*') && str_starts_with($path, rtrim($pattern, '/*'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Constant-time compare the request token against the session token.
     * csrf_token() lazily starts the session and mints a token if none exists.
     */
    private function tokensMatch(Request $request): bool
    {
        $provided = $this->tokenFrom($request);

        return is_string($provided) && $provided !== '' && hash_equals(csrf_token(), $provided);
    }

    /** Pull the token from the form field first, then the standard headers. */
    private function tokenFrom(Request $request): ?string
    {
        $token = $request->post('_token');
        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $request->header('X-CSRF-TOKEN')
            ?? $request->header('X-XSRF-TOKEN');
    }
}
