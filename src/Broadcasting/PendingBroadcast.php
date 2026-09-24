<?php

namespace Nitro\Broadcasting;

use Nitro\Broadcasting\Contracts\ShouldBroadcast;
use Nitro\Events\Contracts\Dispatcher;

/**
 * What broadcast() hands back, so the call can be qualified before it goes.
 *
 *     broadcast(new MessageSent($message))->toOthers();
 *     broadcast(new MessageSent($message))->via('redis');
 *
 * The event is dispatched when this object is destroyed, which is what lets a
 * bare `broadcast($event);` work with nothing chained after it — the same
 * shape as {@see \Nitro\Queue\PendingDispatch}.
 */
class PendingBroadcast
{
    private bool $sent = false;

    public function __construct(
        private Dispatcher $events,
        private object $event,
    ) {}

    /**
     * Do not send this to the connection that caused it.
     *
     * The sender's own client already has it, so without this they see the
     * thing twice.
     */
    public function toOthers(): static
    {
        if (method_exists($this->event, 'dontBroadcastToCurrentUser')) {
            $this->event->dontBroadcastToCurrentUser();
        }

        return $this;
    }

    /** Send it on a named connection rather than the default. */
    public function via(string $connection): static
    {
        if (method_exists($this->event, 'broadcastVia')) {
            $this->event->broadcastVia($connection);
        }

        return $this;
    }

    /** Send it now, rather than waiting for this object to fall out of scope. */
    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        $this->sent = true;

        $this->events->dispatch($this->event);
    }

    public function __destruct()
    {
        // Throwing in a destructor obscures whatever was actually going on,
        // so a failure here is reported and the teardown continues. A caller
        // that wants to handle it calls send() itself.
        try {
            $this->send();
        } catch (\Throwable $exception) {
            error_log('[broadcast] pending broadcast failed: ' . $exception->getMessage());
        }
    }
}
