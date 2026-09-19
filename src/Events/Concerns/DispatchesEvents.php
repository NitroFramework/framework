<?php

namespace Nitro\Events\Concerns;

use Nitro\Events\Contracts\Dispatcher;
use Nitro\Events\Contracts\TogglesEvents;

/**
 * Concern: a convenience API for dispatching events through the dispatcher.
 *
 * Typed to the contract rather than the bundled Dispatcher, because this trait
 * is how most of the framework emits events — a concrete hint here would have
 * made every layer that uses it unusable with another bus.
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
     * Check if events are enabled.
     *
     * Being silenceable is optional, so a dispatcher that does not offer
     * {@see TogglesEvents} counts as enabled. That is the safe reading: the
     * alternative is events silently not firing because the question could
     * not be asked.
     */
    protected function shouldDispatchEvents(): bool
    {
        if ($this->dispatcher === null) {
            return false;
        }

        return ! $this->dispatcher instanceof TogglesEvents || $this->dispatcher->isEnabled();
    }
}
