<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * Credentials checked out, before the user was signed in.
 */
class Validated
{
    public function __construct(
        public Authenticatable $user,
        public ?string $guard = null,
    ) {}
}
