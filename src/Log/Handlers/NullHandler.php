<?php

namespace Nitro\Log\Handlers;

/**
 * Discards everything written to it.
 *
 * The channel to point something at when you want it silenced without
 * removing the calls that write to it.
 */
class NullHandler implements Handler
{
    public function write(string $level, string $message, array $context = []): void
    {
        //
    }
}
