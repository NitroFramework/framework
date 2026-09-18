<?php

namespace Nitro\Facades;

/**
 * Event facade — dispatches events and registers listeners.
 *
 *   Event::listen(OrderPlaced::class, SendReceipt::class);
 *   Event::dispatch(new OrderPlaced($order));
 *
 * @method static void listen(string|array $events, mixed $listener = null)
 * @method static mixed dispatch(object|string $event, mixed $payload = [])
 * @method static void forget(string $event)
 * @method static bool hasListeners(string $event)
 * @method static void subscribe(object|string $subscriber)
 */
class Event extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'events';
    }
}
