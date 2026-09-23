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
     * A client that builds its own requests has no markup to read a token
     * from, so it reads the XSRF-TOKEN cookie and echoes it back in the
     * X-XSRF-TOKEN header.
     *
     * Deliberately not http-only: a cookie script cannot read is a cookie
     * that cannot be echoed back, which defeats the whole exchange. It is
     * safe to expose because possession of the token is not authentication —
     * it only proves the request came from a page this application served.
     */
    private function addCookieToResponse(Request $request, Response $response): Response
    {
        // Returns '' when there is no session to start, where a cookie
        // would mean nothing.
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
     * Guarded because this runs as a response passes through, where an
     * error would break a request that already succeeded.
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

    /**
     * Whether this URI is exempt from verification.
     *
     * Matched through the shared trait, so an exemption means the same
     * thing here as it does for maintenance mode.
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
     * Decrypt an X-XSRF-TOKEN header.
     *
     * It carries the XSRF-TOKEN cookie's value, which is encrypted; the
     * header is not a cookie, so nothing else has decrypted it.
     */
    private function decryptXsrf(string $value): ?string
    {
        try {
            $encrypter = app(Encrypter::class);

            /*
             * The two steps EncryptCookies performs on an incoming cookie:
             * decryptString, then strip the name-bound prefix.
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
