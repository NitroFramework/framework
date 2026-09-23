<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * A user was signed out.
 */
class Logout
{
    public function __construct(
        public Authenticatable $user,
        public ?string $guard = null,
    ) {}
}
