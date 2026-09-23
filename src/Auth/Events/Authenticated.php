<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * A signed-in user was resolved for this request.
 */
class Authenticated
{
    public function __construct(
        public Authenticatable $user,
        public ?string $guard = null,
    ) {}
}
