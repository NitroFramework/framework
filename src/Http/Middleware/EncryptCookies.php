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
    public function __construct(
        protected Encrypter $encrypter
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $request->setCookies($this->decrypt($request->cookie()));

        return $this->encrypt($next($request));
    }

    /** Names that are never encrypted/decrypted. */
    protected function except(): array
    {
        return array_merge(
            [(string) config('session.cookie', 'nitro_session')],
            (array) config('cookie.except', []),
        );
    }

    protected function isExcepted(string $name): bool
    {
        return in_array($name, $this->except(), true);
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
                $cookies[$name] = CookieValuePrefix::validate($name, $decrypted, [$this->encrypter->getKey()]);
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
