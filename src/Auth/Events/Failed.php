<?php

namespace Nitro\Auth\Events;

use Nitro\Auth\Contracts\Authenticatable;

/**
 * Credentials were offered and refused.
 */
class Failed
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $credentials,
        public ?Authenticatable $user = null,
        public ?string $guard = null,
    ) {}
}
