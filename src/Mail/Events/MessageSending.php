<?php

namespace Nitro\Mail\Events;

use Nitro\Mail\Message;

/**
 * A message is about to be handed to the transport.
 *
 * The place to stamp a header on every message the application sends, or to
 * redirect mail somewhere safe on a staging environment. A listener returning
 * false stops the send.
 */
class MessageSending
{
    public function __construct(public Message $message) {}
}
