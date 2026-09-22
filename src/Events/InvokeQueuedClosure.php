<?php

namespace Nitro\Events;

use Laravel\SerializableClosure\SerializableClosure;
use Nitro\Queue\Job;
use Throwable;

/**
 * The job that runs a queued closure listener.
 *
 * Pushed by {@see QueuedClosure} when the event fires, carrying the
 * closure and the event that triggered it.
 */
class InvokeQueuedClosure extends Job
{
    /**
     * @param array<int, mixed>                  $arguments The event, as dispatched.
     * @param array<int, SerializableClosure>    $catchCallbacks
     */
    public function __construct(
        public SerializableClosure $closure,
        public array $arguments = [],
        public array $catchCallbacks = [],
    ) {}

    public function handle(): void
    {
        ($this->closure->getClosure())(...$this->arguments);
    }

    /** Hand the failure, and what it was working on, to the catch callbacks. */
    public function failed(Throwable $exception): void
    {
        foreach ($this->catchCallbacks as $callback) {
            $callback->getClosure()(...[...$this->arguments, $exception]);
        }
    }
}
