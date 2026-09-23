<?php

namespace Nitro\Auth\Listeners;

use Nitro\Auth\Contracts\MustVerifyEmail;
use Nitro\Auth\Events\Registered;

/**
 * Sends the verification link when an account is created.
 *
 * Registered on the Registered event, so a sign-up flow raises one
 * event rather than remembering to send the mail at each call site.
 */
class SendEmailVerificationNotification
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            return;
        }

        if (method_exists($user, 'sendEmailVerificationNotification')) {
            $user->sendEmailVerificationNotification();
        }
    }
}
