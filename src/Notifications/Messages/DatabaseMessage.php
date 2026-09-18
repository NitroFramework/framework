<?php

namespace Nitro\Notifications\Messages;

/**
 * The payload a notification stores in the database.
 */
class DatabaseMessage
{
    /**
     * @param array<string, mixed> $data Stored as the notification's payload.
     */
    public function __construct(
        public array $data = [],
    ) {}
}
