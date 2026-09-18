<?php

namespace Nitro\Broadcasting\Contracts;

use Nitro\Broadcasting\Channel;

/**
 * Marks an event that should also be sent to listening clients.
 *
 *     class OrderShipped implements ShouldBroadcast
 *     {
 *         public function broadcastOn(): array
 *         {
 *             return [new PrivateChannel('orders.' . $this->order->id)];
 *         }
 *     }
 */
interface ShouldBroadcast
{
    /**
     * Channels the event goes out on.
     *
     * @return array<int, Channel>|Channel
     */
    public function broadcastOn(): array|Channel;
}
