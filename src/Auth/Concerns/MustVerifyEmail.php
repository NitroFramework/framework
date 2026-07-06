<?php

namespace Nitro\Auth\Concerns;

/**
 * Default {@see \Nitro\Auth\Contracts\MustVerifyEmail} implementation for models.
 *
 * Backed by a nullable `email_verified_at` column (the verification timestamp).
 * The column must be fillable for markEmailAsVerified() to persist via update().
 */
trait MustVerifyEmail
{
    public function hasVerifiedEmail(): bool
    {
        return !empty($this->getAttribute('email_verified_at'));
    }

    public function markEmailAsVerified(): bool
    {
        return $this->update(['email_verified_at' => date('Y-m-d H:i:s')]);
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->getAttribute('email');
    }
}
