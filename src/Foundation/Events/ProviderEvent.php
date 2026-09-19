<?php

namespace Nitro\Foundation\Events;

/**
 * Payload for provider.registering, provider.registered, provider.booting and
 * provider.booted.
 *
 * Just the class name. The provider instance itself is deliberately not here:
 * a listener holding one could call register() or boot() a second time, and
 * the framework's guarantee that each runs once is worth more than the
 * convenience.
 */
class ProviderEvent
{
    /**
     * @param class-string $provider The service provider this event is about.
     */
    public function __construct(
        public readonly string $provider,
    ) {}
}
