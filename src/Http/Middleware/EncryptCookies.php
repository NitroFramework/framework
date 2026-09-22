<?php

namespace Nitro\Http\Middleware;

use Nitro\Cookie\CookieValuePrefix;
use Nitro\Encryption\Contracts\Encrypter;
use Nitro\Encryption\DecryptException;
use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * Decrypts incoming request cookies and encrypts outgoing response cookies, so
 * cookie values are opaque and tamper-evident in the browser. Each value is
 * bound to its name with an HMAC prefix (CookieValuePrefix).
 *
 * The session cookie is excepted — the session layer manages it as a plaintext
 * random id — as are any names in config('cookie.except').
 */
class EncryptCookies
{
    /** Memoized never-encrypt list — the config lookups happen once, not per cookie. */
    private ?array $except = null;

    public function __construct(
        protected Encrypter $encrypter
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $request->setCookies($this->decrypt($request->cookie()));

        return $this->encrypt($next($request));
    }

    /** @var array<int, string> Exempted for every instance, not just this one. */
    protected static array $neverEncrypt = [];

    /** Names that are never encrypted/decrypted. */
    protected function exempted(): array
    {
        return $this->except ??= array_merge(
            [(string) config('session.cookie', 'nitro_session')],
            (array) config('cookie.except', []),
        );
    }

    /**
     * Exempt a cookie on this instance.
     *
     * The case for it is a cookie something outside the application reads —
     * an analytics script, a load balancer — which cannot decrypt what it
     * finds.
     *
     * @param array<int, string>|string $name
     */
    public function disableFor(array|string $name): void
    {
        $this->except = array_merge($this->exempted(), (array) $name);
    }

    /** Whether a cookie is exempt, by either route. */
    public function isDisabled(string $name): bool
    {
        return in_array($name, array_merge($this->exempted(), static::$neverEncrypt), true);
    }

    /**
     * Exempt cookies for every instance of this middleware.
     *
     * @param array<int, string>|string $cookies
     */
    public static function except(array|string $cookies): void
    {
        static::$neverEncrypt = array_values(array_unique(
            array_merge(static::$neverEncrypt, (array) $cookies)
        ));
    }

    /** Drop the global state, so one test cannot leak into the next. */
    public static function flushState(): void
    {
        static::$neverEncrypt = [];
    }

    protected function isExcepted(string $name): bool
    {
        return $this->isDisabled($name);
    }

    /** Replace each encrypted request cookie with its plaintext (invalid → null). */
    protected function decrypt(array $cookies): array
    {
        foreach ($cookies as $name => $value) {
            if ($this->isExcepted($name) || ! is_string($value)) {
                continue;
            }

            try {
                $decrypted = $this->encrypter->decryptString($value);
                $cookies[$name] = CookieValuePrefix::validate($name, $decrypted, $this->encrypter->getAllKeys());
            } catch (DecryptException) {
                $cookies[$name] = null;
            }
        }

        return $cookies;
    }

    /** Encrypt each outgoing cookie, prefixing its value with the name HMAC. */
    protected function encrypt(Response $response): Response
    {
        foreach ($response->cookies() as $cookie) {
            if ($this->isExcepted($cookie->name) || $cookie->value === '') {
                continue;
            }

            $value = CookieValuePrefix::create($cookie->name, $this->encrypter->getKey()) . $cookie->value;
            $response->withCookie($cookie->withValue($this->encrypter->encryptString($value)));
        }

        return $response;
    }
}
