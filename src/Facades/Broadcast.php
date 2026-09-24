<?php

namespace Nitro\Facades;

use Nitro\Broadcasting\BroadcastManager;
use Nitro\Broadcasting\Drivers\FakeBroadcaster;

/**
 * Broadcast facade — sends events to listening clients.
 *
 *   Broadcast::event(new OrderShipped($order));
 *   Broadcast::channel('orders.{id}', fn ($user, $id) => true);
 *
 * Most applications never call this: an event implementing
 * {@see \Nitro\Broadcasting\Contracts\ShouldBroadcast} is broadcast when it is
 * dispatched, and `broadcast($event)->toOthers()` is the usual call site. This
 * is for serving the authorisation endpoint, registering channel authorisers,
 * and sending without an event.
 *
 * @method static void event(\Nitro\Broadcasting\Contracts\ShouldBroadcast $event, ?array $payload = null)
 * @method static void queue(\Nitro\Broadcasting\Contracts\ShouldBroadcast $event)
 * @method static void send(array|string $channels, string $event, array $payload = [])
 * @method static void routes(?array $attributes = null)
 * @method static \Nitro\Broadcasting\BroadcastManager channel(string $pattern, callable|string $callback)
 * @method static bool check(mixed $user, string $channel)
 * @method static array|bool authorise(mixed $user, string $channel)
 * @method static bool hasChannelFor(string $channel)
 * @method static \Nitro\Broadcasting\Contracts\Broadcaster connection(?string $name = null)
 * @method static \Nitro\Broadcasting\Contracts\Broadcaster driver(?string $name = null)
 * @method static \Nitro\Broadcasting\BroadcastManager extend(string $name, \Closure $factory)
 * @method static \Nitro\Broadcasting\BroadcastManager setDefaultDriver(string $name)
 * @method static string getDefaultDriver()
 * @method static array getChannels()
 *
 * @see \Nitro\Broadcasting\BroadcastManager
 */
class Broadcast extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'broadcast';
    }

    /**
     * Record broadcasts instead of sending them.
     *
     * Returns the recorder, and also makes it the default driver, so the
     * assertions can be reached either through it or through this facade.
     */
    public static function fake(): FakeBroadcaster
    {
        $manager = app(BroadcastManager::class);

        $fake = new FakeBroadcaster();

        $manager->extend('fake', static fn (): FakeBroadcaster => $fake);
        $manager->setDefaultDriver('fake');

        return $fake;
    }
}
