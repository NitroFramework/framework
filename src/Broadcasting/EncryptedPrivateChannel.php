<?php

namespace Nitro\Broadcasting;

/**
 * A private channel whose payloads the socket server cannot read.
 *
 *     new EncryptedPrivateChannel('orders.' . $order->id)
 *
 * A private channel keeps the wrong clients out, but the server relaying the
 * messages still sees every one of them in the clear. On a hosted service that
 * means a third party holds the contents of everything broadcast. With this,
 * the payload is encrypted for the subscribers and the relay carries bytes it
 * cannot open.
 *
 * The client needs the matching key, which is why Echo asks for it at the
 * authorisation endpoint rather than having it up front.
 */
class EncryptedPrivateChannel extends Channel
{
    public function __construct(string $name)
    {
        parent::__construct('private-encrypted-' . $name);
    }
}
