<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * A user account was created.
 */
class Registered
{
    public function __construct(
        public Authenticatable $user,
    ) {}
}
