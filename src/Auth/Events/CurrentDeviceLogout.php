<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * A user signed out of this device, leaving the others alone.
 */
class CurrentDeviceLogout
{
    public function __construct(
        public Authenticatable $user,
        public ?string $guard = null,
    ) {}
}
