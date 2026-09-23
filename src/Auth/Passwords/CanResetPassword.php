<?php

namespace Nitro\Auth\Passwords;

/**
 * A user who can be sent a password reset link.
 *
 * Separate from being authenticatable: an account signed in by a token
 * or an external identity may have no address to send one to.
 */
interface CanResetPassword
{
    /** Where the reset link is sent, and what the token is keyed by. */
    public function getEmailForPasswordReset(): string;

    /** Deliver the link. */
    public function sendPasswordResetNotification(string $token): void;
}
