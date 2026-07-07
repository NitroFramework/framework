<?php

namespace Nitro\Mail\Contracts;

use Nitro\Mail\Message;

/**
 * Sends mail through the configured transport. The plain send() is a convenience
 * for a text body; html() and sendMessage() cover richer messages.
 */
interface Mailer
{
    /** Deliver a plain-text body to an address. */
    public function send(string $to, string $subject, string $body): void;

    /** Deliver an HTML body to an address. */
    public function html(string $to, string $subject, string $html): void;

    /** Deliver a fully-built message. */
    public function sendMessage(Message $message): void;
}
