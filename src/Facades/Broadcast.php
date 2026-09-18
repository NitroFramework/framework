<?php

namespace Nitro\Facades;

/**
 * Broadcast facade — sends events to listening clients.
 *
 *   Broadcast::event(new OrderShipped($order));
 *   Broadcast::channel('orders.{id}', fn ($user, $id) => true);
 *
 * @method static void event(\Nitro\Broadcasting\Contracts\ShouldBroadcast $event, ?array $payload = null)
 * @method static void send(array|string $channels, string $event, array $payload = [])
 * @method static \Nitro\Broadcasting\BroadcastManager channel(string $pattern, callable|string $callback)
 * @method static bool check(mixed $user, string $channel)
 * @method static \Nitro\Broadcasting\Contracts\Broadcaster connection(?string $name = null)
 * @method static \Nitro\Broadcasting\Contracts\Broadcaster driver(?string $name = null)
 * @method static \Nitro\Broadcasting\BroadcastManager extend(string $name, \Closure $factory)
 * @method static array getChannels()
 */
class Broadcast extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'broadcast';
    }
}
