<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * A user confirmed their email address.
 */
class Verified
{
    public function __construct(
        public Authenticatable $user,
    ) {}
}
