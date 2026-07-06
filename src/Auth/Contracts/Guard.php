<?php

namespace Nitro\Auth\Contracts;

/**
 * A stateful authentication guard: who is logged in, and login/logout/attempt.
 */
interface Guard
{
    /** The currently authenticated user, or null. */
    public function user(): ?Authenticatable;

    /** The current user's identifier, or null. */
    public function id(): int|string|null;

    /** Whether a user is authenticated. */
    public function check(): bool;

    /** Whether no user is authenticated. */
    public function guest(): bool;

    /** Attempt to authenticate with the given credentials. */
    public function attempt(array $credentials): bool;

    /** Validate credentials without logging the user in. */
    public function validate(array $credentials): bool;

    /** Log the given user in. */
    public function login(Authenticatable $user): void;

    /** Log in the user with the given id; returns the user, or null if not found. */
    public function loginUsingId(int|string $id): ?Authenticatable;

    /** Log the current user out. */
    public function logout(): void;

    /** Remember where the user was headed before being redirected to login. */
    public function setIntendedUrl(string $url): void;

    /** Get and forget the intended URL, falling back to $default. */
    public function getIntendedUrl(?string $default = null): ?string;
}
