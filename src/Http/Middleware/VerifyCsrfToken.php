<?php

namespace Nitro\Http\Middleware;

use Nitro\Cookie\CookieValuePrefix;
use Nitro\Encryption\Encrypter;
use Nitro\Http\Middleware\Concerns\ExcludesPaths;
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
    use ExcludesPaths;

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
            return $this->addCookieToResponse($request, $next($request));
        }

        throw new HttpException(419, 'CSRF token mismatch.');
    }

    /**
     * Publish the token as a cookie a browser script can read.
     *
     * A form gets its token from `@csrf`, but a client that builds its own
     * requests has no markup to read it from. The convention every such
     * client follows is to look for an XSRF-TOKEN cookie and echo it back in
     * the X-XSRF-TOKEN header — which {@see tokenFrom()} already accepts, so
     * without this the framework was reading a header nothing could send.
     *
     * Deliberately not http-only: a cookie script cannot read is a cookie
     * that cannot be echoed back, which defeats the whole exchange. It is
     * safe to expose because possession of the token is not authentication —
     * it only proves the request came from a page this application served.
     */
    private function addCookieToResponse(Request $request, Response $response): Response
    {
        // csrf_token() starts the session and mints a token if there is none,
        // and returns '' when there is no session to start — a console run or
        // a route outside the web group, where a cookie would mean nothing.
        $token = csrf_token();

        if ($token === '') {
            return $response;
        }

        $config = $this->sessionConfig();

        $response->cookie(
            'XSRF-TOKEN',
            $token,
            (int) ($config['lifetime'] ?? 120),
            (string) ($config['path'] ?? '/'),
            $config['domain'] ?? null,
            (bool) ($config['secure'] ?? false),
            false,
            (string) ($config['same_site'] ?? 'Lax'),
        );

        return $response;
    }

    /**
     * The session cookie settings, or none when configuration is unavailable.
     *
     * Guarded the same way {@see csrf_token()} guards the session: this runs
     * as a response passes through, and a context without a config repository
     * bound should get a cookie with sensible defaults rather than an error
     * raised on the way out of a request that already succeeded.
     *
     * @return array<string, mixed>
     */
    private function sessionConfig(): array
    {
        try {
            return (array) config('session', []);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Read verbs are inherently safe and skip verification. */
    private function isReading(Request $request): bool
    {
        return in_array($request->method(), self::READ_METHODS, true);
    }

    /** Whether the request URI matches a configured exemption. */
    /**
     * Whether this URI is exempt from verification.
     *
     * Matched through the shared trait, so an exemption means the same thing
     * here as it does for maintenance mode. That also gains full-URL matching
     * and the wildcard handling in Request::is(), which the hand-rolled
     * version here only approximated for a trailing '/*'.
     */
    private function isExcept(Request $request): bool
    {
        return $this->except !== [] && $this->inExceptArray($request);
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

        $header = $request->header('X-CSRF-TOKEN');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $xsrf = $request->header('X-XSRF-TOKEN');

        return is_string($xsrf) && $xsrf !== '' ? $this->decryptXsrf($xsrf) : null;
    }

    /**
     * An X-XSRF-TOKEN header holds whatever was in the XSRF-TOKEN cookie, and
     * that cookie is encrypted on the way out.
     *
     * EncryptCookies decrypts incoming *cookies*; this value arrives as a
     * header, so nothing has touched it. Comparing the ciphertext against the
     * session's plaintext token would fail every time — which is the whole
     * reason the header path had never worked.
     */
    private function decryptXsrf(string $value): ?string
    {
        try {
            $encrypter = app(Encrypter::class);

            /*
             * The same two steps EncryptCookies performs on an incoming
             * cookie, because this value is one — decryptString, then strip
             * the name-bound prefix it was encrypted with. Using the plain
             * decrypt() would leave that prefix in place and never match.
             */
            return CookieValuePrefix::validate(
                'XSRF-TOKEN',
                $encrypter->decryptString($value),
                $encrypter->getAllKeys(),
            );
        } catch (\Throwable) {
            // A value this application did not encrypt proves nothing, and a
            // forged one should read as a mismatch rather than an error.
            return null;
        }
    }
}
