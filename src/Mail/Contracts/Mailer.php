<?php

namespace Nitro\Mail\Contracts;

use Nitro\Mail\Mailable;
use Nitro\Mail\Message;

/**
 * Sends mail through the configured transport.
 */
interface Mailer
{
    /** Deliver a built message, or a mailable that describes one. */
    public function send(Message|Mailable $message): void;

    /** Deliver a plain-text body to an address. */
    public function raw(string $to, string $subject, string $text): void;

    /** Deliver an HTML body to an address. */
    public function html(string $to, string $subject, string $html): void;
}
