<?php

namespace Nitro\Events\Concerns;

use Nitro\Events\Dispatcher;

/**
 * Concern: a convenience API for dispatching events through the dispatcher.
 */
trait DispatchesEvents
{
    private ?Dispatcher $dispatcher = null;

    public function setDispatcher(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Dispatch an event.
     *
     * Short-circuits when no dispatcher is wired or when nothing is listening
     * for this event, so we don't pay the cost of building $data on hot paths.
     */
    protected function event(string $event, array $data = []): void
    {
        if ($this->dispatcher === null || !$this->dispatcher->hasListeners($event)) {
            return;
        }
        $this->dispatcher->dispatch($event, $data);
    }

    /** Like event() but the payload builder is only called when needed. */
    protected function eventLazy(string $event, \Closure $payloadBuilder): void
    {
        if ($this->dispatcher === null || !$this->dispatcher->hasListeners($event)) {
            return;
        }
        $this->dispatcher->dispatch($event, $payloadBuilder());
    }

    /**
     * Check if events are enabled
     */
    protected function shouldDispatchEvents(): bool
    {
        return $this->dispatcher !== null && $this->dispatcher->isEnabled();
    }
}
