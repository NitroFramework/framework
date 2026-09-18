<?php

namespace Nitro\Broadcasting;

/**
 * A private channel that also reports who is listening.
 */
class PresenceChannel extends Channel
{
    public function __construct(string $name)
    {
        parent::__construct('presence-' . $name);
    }
}
