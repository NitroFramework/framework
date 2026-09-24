<?php

use Nitro\Broadcasting\PendingBroadcast;
use Nitro\Events\Contracts\Dispatcher;
use Nitro\Events\QueuedClosure;

if (! function_exists('event')) {
    /**
     * Dispatch an event.
     *
     *   event(new OrderPlaced($order));
     *   event('report.ready', [$report]);
     *
     * @param  array<int, mixed>|mixed $payload
     * @return array<int, mixed>|mixed What the listeners returned.
     */
    function event(string|object $event, mixed $payload = [], bool $halt = false): mixed
    {
        return app(Dispatcher::class)->dispatch($event, $payload, $halt);
    }
}

if (! function_exists('broadcast')) {
    /**
     * Dispatch an event, with the broadcast-specific options to hand.
     *
     *   broadcast(new MessageSent($message))->toOthers();
     *   broadcast(new StockMoved($item))->via('redis');
     *
     * The event goes through the same dispatcher as event(), so its listeners
     * still run — this only gives the call site somewhere to say "not back to
     * the sender" and "on that connection". Without the chained call it
     * behaves exactly like event().
     */
    function broadcast(object $event): PendingBroadcast
    {
        return new PendingBroadcast(app(Dispatcher::class), $event);
    }
}

if (! function_exists('queueable')) {
    /**
     * Wrap a closure listener so it runs on the queue.
     *
     *   Event::listen(queueable(function (OrderPaid $event) {
     *       Receipt::for($event->orderId)->send();
     *   })->onQueue('mail'));
     */
    function queueable(Closure $closure): QueuedClosure
    {
        return new QueuedClosure($closure);
    }
}
