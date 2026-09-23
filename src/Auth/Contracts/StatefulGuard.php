<?php

namespace Nitro\Auth\Contracts;

/**
 * A guard that can sign a user in and out, and keep them signed in.
 *
 * The session guard is one; a token guard is not, because there is no
 * state for it to establish — the credential arrives with every request.
 */
interface StatefulGuard extends Guard
{
    /** Check credentials and sign the user in if they hold. */
    public function attempt(array $credentials, bool $remember = false): bool;

    /** Check credentials and set the user for this request only. */
    public function once(array $credentials): bool;

    public function login(Authenticatable $user, bool $remember = false): void;

    public function loginUsingId(int|string $id, bool $remember = false): ?Authenticatable;

    /** Set a user for this request only, by identifier. */
    public function onceUsingId(int|string $id): ?Authenticatable;

    /** Whether this request was authenticated by a remember-me cookie. */
    public function viaRemember(): bool;

    public function logout(): void;

    /** Where to send the user once they have signed in. */
    public function setIntendedUrl(string $url): void;

    public function getIntendedUrl(?string $default = null): ?string;
}
