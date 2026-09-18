<?php

namespace Nitro\Broadcasting;

/**
 * A channel a listener must be authorised to join.
 */
class PrivateChannel extends Channel
{
    public function __construct(string $name)
    {
        parent::__construct('private-' . $name);
    }
}
