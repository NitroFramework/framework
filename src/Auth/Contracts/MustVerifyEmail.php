<?php

namespace Nitro\Auth\Contracts;

/**
 * Marks a user whose email address must be verified.
 *
 * The 'verified' middleware and the email-verification controllers check and
 * flip this state; the address is exposed separately so the verification link's
 * hash can be derived from it.
 */
interface MustVerifyEmail
{
    /** Whether the email has been verified. */
    public function hasVerifiedEmail(): bool;

    /** Mark the email as verified (persists). */
    public function markEmailAsVerified(): bool;

    /** The email address being verified — used to derive the link hash. */
    public function getEmailForVerification(): string;
}
