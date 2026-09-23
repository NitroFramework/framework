<?php

namespace Nitro\Auth\Events;

/**
 * Credentials were offered, before they were checked.
 */
class Attempting
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $credentials,
        public ?string $guard = null,
        public bool $remember = false,
    ) {}
}
