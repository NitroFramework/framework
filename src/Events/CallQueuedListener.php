<?php

namespace Nitro\Events;

use Nitro\Queue\Job;

/**
 * The job that runs a queued event listener.
 *
 * A listener implementing ShouldQueue is not called when the event is
 * dispatched; this job is pushed instead, carrying the listener's class name
 * and the event itself. The worker resolves the listener from the container and
 * calls it, so the listener is written exactly as a synchronous one would be
 * and the only difference is where it runs.
 *
 * The event is serialized into the payload, which is the constraint worth
 * knowing: an event carrying a whole model graph, a closure or a PDO handle
 * will not survive the trip. Put ids on events, not objects — the listener
 * re-queries, and it gets current data rather than a snapshot from whenever the
 * job was pushed.
 */
class CallQueuedListener extends Job
{
    /**
     * @param  class-string  $listenerClass
     * @param  string        $method  Usually 'handle'.
     */
    public function __construct(
        public string $listenerClass,
        public string $method,
        public mixed $event,
    ) {}

    public function handle(): void
    {
        $listener = \app($this->listenerClass);

        $listener->{$this->method}($this->event);
    }

    /**
     * Route to the queue the listener asks for, so a slow listener can be kept
     * off the queue that sends password resets.
     */
    public function queueName(): string
    {
        if (property_exists($this->listenerClass, 'queue')) {
            return (new \ReflectionClass($this->listenerClass))
                ->getDefaultProperties()['queue'] ?? 'default';
        }

        return 'default';
    }

    /**
     * Name the listener in the failed-jobs table. Without this every failure is
     * recorded as CallQueuedListener and the table cannot tell you what broke.
     */
    public function displayName(): string
    {
        return $this->listenerClass;
    }
}
