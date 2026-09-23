<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * A user signed out of every device but this one.
 */
class OtherDeviceLogout
{
    public function __construct(
        public Authenticatable $user,
        public ?string $guard = null,
    ) {}
}
