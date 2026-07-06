<?php

namespace Nitro\Mail\Contracts;

/**
 * Minimal mail-sending seam.
 *
 * Deliberately tiny: the auth flows only need to deliver a plain-text body to
 * an address. A real SMTP/transactional driver implements the same contract and
 * swaps in via the container binding — no caller changes.
 */
interface Mailer
{
    /**
     * Deliver a message. Implementations may queue, log, or transmit it.
     */
    public function send(string $to, string $subject, string $body): void;
}
