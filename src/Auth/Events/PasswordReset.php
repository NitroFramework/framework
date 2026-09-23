<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * A user's password was reset.
 */
class PasswordReset
{
    public function __construct(
        public Authenticatable $user,
    ) {}
}
