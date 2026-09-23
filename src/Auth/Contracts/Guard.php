<?php

namespace Nitro\Auth\Contracts;

/**
 * Says who the current user is, if anyone.
 *
 * Deliberately read-only: a guard that authenticates per request from a
 * token or a header can answer all of this and has no sign-in to
 * perform. Signing in and out is {@see StatefulGuard}.
 */
interface Guard
{
    public function user(): ?Authenticatable;

    public function id(): int|string|null;

    public function check(): bool;

    public function guest(): bool;

    /** Whether credentials hold, without signing anybody in. */
    public function validate(array $credentials): bool;

    /** Whether a user has already been resolved this request. */
    public function hasUser(): bool;

    /** Set the user for this request, without touching any store. */
    public function setUser(Authenticatable $user): static;
}
