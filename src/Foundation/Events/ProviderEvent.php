<?php

namespace Nitro\Foundation\Events;

/**
 * Payload for the provider.registering, registered, booting and booted events.
 *
 * Carries the class name only, so a listener cannot run a provider a second time.
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
