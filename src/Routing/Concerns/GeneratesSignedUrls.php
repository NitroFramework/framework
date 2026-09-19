<?php

namespace Nitro\Routing\Concerns;

use DateTimeInterface;
use Nitro\Encryption\MissingAppKeyException;
use Nitro\Http\Request;

/**
 * Signed URL generation and verification for the {@see \Nitro\Routing\Router}.
 *
 * A signed URL carries a keyed hash of itself, so a link can be handed to
 * somebody who is not logged in and still be trusted: an unsubscribe link, a
 * password reset, an email-confirmation click. Changing any part of the URL
 * invalidates the signature, and a temporary one carries its own expiry.
 *
 * The signature covers the path and the query in a fixed order, so the same
 * URL signs identically however its parameters were written. It does not cover
 * the host — these are relative signatures, which keep working when an app
 * sits behind a proxy or is reached by more than one hostname.
 */
trait GeneratesSignedUrls
{
    /** Query parameter holding the keyed hash. */
    public const SIGNATURE_KEY = 'signature';

    /** Query parameter holding a temporary URL's expiry, as a Unix timestamp. */
    public const EXPIRES_KEY = 'expires';

    /**
     * A URL for a named route with a signature appended.
     *
     * @param int|DateTimeInterface|null $expiration When the link stops working;
     *        null signs it with no expiry.
     */
    public function signedRoute(string $name, array $parameters = [], int|DateTimeInterface|null $expiration = null): string
    {
        if ($expiration !== null) {
            $parameters[self::EXPIRES_KEY] = $expiration instanceof DateTimeInterface
                ? $expiration->getTimestamp()
                : time() + $expiration;
        }

        $url = $this->route($name, $parameters);

        return $url
            . (str_contains($url, '?') ? '&' : '?')
            . self::SIGNATURE_KEY . '=' . $this->signature($url);
    }

    /**
     * A signed URL that stops working after $expiration.
     *
     * @param int|DateTimeInterface $expiration Seconds from now, or the moment itself.
     */
    public function temporarySignedRoute(string $name, int|DateTimeInterface $expiration, array $parameters = []): string
    {
        return $this->signedRoute($name, $parameters, $expiration);
    }

    /**
     * Whether a request carries a signature that matches its own URL and has
     * not expired.
     *
     * Compared with hash_equals, so a caller cannot learn the signature one
     * byte at a time from how long the comparison took.
     */
    public function hasValidSignature(Request $request): bool
    {
        $query = $request->query();
        $query = is_array($query) ? $query : [];

        $signature = $query[self::SIGNATURE_KEY] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        if ($this->signatureHasExpired($query)) {
            return false;
        }

        unset($query[self::SIGNATURE_KEY]);

        return hash_equals($this->signature($this->canonicalUrl($request->path(), $query)), $signature);
    }

    /** Whether the URL declared an expiry that is now in the past. */
    protected function signatureHasExpired(array $query): bool
    {
        $expires = $query[self::EXPIRES_KEY] ?? null;

        return $expires !== null && (int) $expires < time();
    }

    /**
     * The exact string that gets hashed.
     *
     * The query is sorted by key, so a link whose parameters arrive in a
     * different order than they were written still verifies — a browser, a
     * mail client and a proxy are all entitled to reorder them.
     */
    protected function canonicalUrl(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        ksort($query);

        return $path . '?' . http_build_query($query);
    }

    /** The keyed hash of a URL, using the application key. */
    protected function signature(string $url): string
    {
        [$path, $queryString] = array_pad(explode('?', $url, 2), 2, '');

        $query = [];

        if ($queryString !== '') {
            parse_str($queryString, $query);
        }

        return hash_hmac('sha256', $this->canonicalUrl($path, $query), $this->signingKey());
    }

    /**
     * The application key, in the same form the encrypter reads it.
     *
     * A signature is only a promise if the key is secret, so an app with no
     * key configured is told so rather than handed links anybody can forge.
     *
     * @throws MissingAppKeyException When no application key is configured.
     */
    protected function signingKey(): string
    {
        $key = (string) $this->config->get('app.key', '');

        if ($key === '') {
            throw new MissingAppKeyException(
                'No application key set. Signed URLs cannot be trusted without one — run `nitro key:generate`.'
            );
        }

        return str_starts_with($key, 'base64:')
            ? (string) base64_decode(substr($key, 7), true)
            : $key;
    }
}
