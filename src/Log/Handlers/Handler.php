<?php

namespace Nitro\Log\Handlers;

/**
 * Somewhere a log line can be written.
 *
 * A channel is a handler plus a minimum level; the manager builds one of these
 * per configured channel and the {@see \Nitro\Log\Logger} writes through it.
 */
interface Handler
{
    /**
     * Write one formatted line.
     */
    public function write(string $level, string $message, array $context = []): void;
}
