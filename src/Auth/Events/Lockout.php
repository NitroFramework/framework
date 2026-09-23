<?php

namespace Nitro\Auth\Events;

/**
 * Too many attempts were made, and further ones are refused.
 */
class Lockout
{
    public function __construct(
        public mixed $request = null,
    ) {}
}
