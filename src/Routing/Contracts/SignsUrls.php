<?php

namespace Nitro\Routing\Contracts;

use DateTimeInterface;
use Nitro\Http\Request;

/**
 * A router that can sign a URL and check the signature back.
 *
 * A signed URL carries a keyed hash of itself, so a link can be given to
 * somebody with no session and still be trusted — an unsubscribe link, a
 * password reset, an emailed confirmation. Changing any part of the URL
 * invalidates it.
 *
 * Optional, and separate from {@see RouterInterface} for the same reason
 * {@see ExtendableRouter} is: signing is not part of being a router, and an
 * application bringing its own should not have to implement it. The 'signed'
 * middleware refuses the request outright when the router in use cannot
 * verify — a signature check that quietly passes is worse than none.
 */
interface SignsUrls
{
    /**
     * A URL for a named route with a signature appended.
     *
     * @param int|DateTimeInterface|null $expiration Seconds from now, the moment
     *        itself, or null for a signature that does not expire.
     */
    public function signedRoute(string $name, array $parameters = [], int|DateTimeInterface|null $expiration = null): string;

    /** A signed URL that stops working after $expiration. */
    public function temporarySignedRoute(string $name, int|DateTimeInterface $expiration, array $parameters = []): string;

    /** Whether the request's URL carries a signature that matches it and has not expired. */
    public function hasValidSignature(Request $request): bool;
}
