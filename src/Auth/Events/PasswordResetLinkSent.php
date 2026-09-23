<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * A password reset link was sent to a user.
 */
class PasswordResetLinkSent
{
    public function __construct(
        public Authenticatable $user,
    ) {}
}
