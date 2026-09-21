<?php

namespace Nitro\Scheduling;

use Closure;
use DateTimeInterface;

/** Collects scheduled events. The app populates it (e.g. in a provider's boot). */
class Schedule
{
    /** @var array<int, Event> */
    protected array $events = [];

    /**
     * Every event this schedule creates is handed the context, so running one
     * needs no argument — a caller with an Event in hand has everything.
     */
    public function __construct(protected ScheduleContext $context) {}

    public function call(callable $callback): Event
    {
        return $this->events[] = new Event(Closure::fromCallable($callback), 'callback', $this->context);
    }

    public function command(string $command): Event
    {
        return $this->events[] = new Event($command, 'command', $this->context);
    }

    public function job(object $job): Event
    {
        return $this->events[] = new Event($job, 'job', $this->context);
    }

    public function exec(string $command): Event
    {
        return $this->events[] = new Event($command, 'exec', $this->context);
    }

    /** @return array<int, Event> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return array<int, Event> Events due at the given moment. */
    public function dueEvents(DateTimeInterface $now): array
    {
        return array_values(array_filter($this->events, static fn (Event $exception): bool => $exception->isDue($now)));
    }
}
