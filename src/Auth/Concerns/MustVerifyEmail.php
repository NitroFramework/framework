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

    /**
     * Send the verification link.
     *
     * Overridable, so an application that verifies by SMS or through an
     * identity provider sends whatever that takes instead.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (method_exists($this, 'notify')) {
            $this->notify(new \Nitro\Auth\Notifications\VerifyEmail());
        }
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->getAttribute('email');
    }
}
