<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * A user was signed in.
 */
class Login
{
    public function __construct(
        public Authenticatable $user,
        public ?string $guard = null,
        public bool $remember = false,
    ) {}
}
