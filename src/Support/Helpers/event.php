<?php

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
