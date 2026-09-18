<?php

namespace Nitro\Broadcasting;

use Stringable;

/**
 * A channel an event is broadcast on.
 *
 *     new Channel('orders');
 *     new PrivateChannel('orders.' . $order->id);
 */
class Channel implements Stringable
{
    public function __construct(
        public readonly string $name,
    ) {}

    public function __toString(): string
    {
        return $this->name;
    }
}
