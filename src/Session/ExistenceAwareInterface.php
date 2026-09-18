<?php

namespace Nitro\Session;

use SessionHandlerInterface;

/**
 * Implemented by handlers that need to know whether a session is already
 * persisted, so a write can choose between an insert and an update.
 */
interface ExistenceAwareInterface
{
    /**
     * Set the existence state for the session.
     */
    public function setExists(bool $value): SessionHandlerInterface;
}
