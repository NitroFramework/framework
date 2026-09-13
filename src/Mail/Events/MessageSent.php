<?php

namespace Nitro\Mail\Events;

use Nitro\Mail\Message;

/**
 * A message has been handed to the transport.
 *
 * Handed over, not delivered — nothing here knows whether a mail server
 * accepted it, let alone whether a person read it. That distinction is the
 * whole reason this event exists: an application that wants to answer "did
 * this person get their certificate email?" has to write something down at the
 * moment of sending, and the alternative is wrapping the mailer.
 */
class MessageSent
{
    public function __construct(public Message $message) {}
}
