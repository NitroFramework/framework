<?php

namespace Nitro\Mail\Contracts;

use Nitro\Mail\Message;

/**
 * Sends mail through the configured transport.
 */
interface Mailer
{
    /** Deliver a fully-built message. */
    public function send(Message $message): void;

    /** Deliver a plain-text body to an address. */
    public function raw(string $to, string $subject, string $text): void;

    /** Deliver an HTML body to an address. */
    public function html(string $to, string $subject, string $html): void;
}
