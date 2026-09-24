<?php

namespace Nitro\Broadcasting;

use Nitro\Broadcasting\Contracts\ShouldBroadcast;
use Nitro\Queue\Job;

/**
 * The job that sends a queued broadcast.
 *
 * A broadcast is a network call to something the application does not control,
 * so by default it happens on a worker rather than in the request. This is
 * what carries the event there.
 *
 * The event is held as an object and serialized with the job, which is why a
 * broadcast event's properties have to survive serialize() — the same rule as
 * any other queued job's constructor arguments.
 */
class BroadcastEvent extends Job
{
    protected int $tries = 3;

    public function __construct(
        public ShouldBroadcast $event,
    ) {}

    public function handle(): void
    {
        // Resolved here rather than type-hinted, because the base Job declares
        // handle() with no parameters and a subclass may not add a required
        // one.
        app(BroadcastManager::class)->event($this->event);
    }

    /** Named for the event it carries, so a queue listing reads usefully. */
    public function displayName(): string
    {
        return $this->event::class;
    }

    public function queueName(): string
    {
        return method_exists($this->event, 'broadcastQueue')
            ? (string) $this->event->broadcastQueue()
            : parent::queueName();
    }
}
