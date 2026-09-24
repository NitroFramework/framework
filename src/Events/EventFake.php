<?php

namespace Nitro\Events;

use Closure;
use PHPUnit\Framework\Assert;

/**
 * A dispatcher that records events instead of running their listeners.
 *
 *     Event::fake();
 *
 *     $this->post('/orders/42/ship');
 *
 *     Event::assertDispatched(OrderShipped::class);
 *
 * The listeners not running is the point, not a side effect. A test about
 * shipping an order should not also send mail, hit Slack and write analytics —
 * it should say "the order announced itself", and let the listeners have tests
 * of their own.
 *
 * Some listeners are the thing under test, so they can be let through:
 *
 *     Event::fake()->except(OrderShipped::class);
 */
class EventFake extends Dispatcher
{
    /** @var array<int, array{event: string, payload: array<int, mixed>}> */
    protected array $dispatched = [];

    /**
     * Events that still reach their listeners.
     *
     * @var array<int, string>
     */
    protected array $except = [];

    /**
     * @param array<int, string> $except
     */
    public function __construct(
        protected ?Dispatcher $real = null,
        array $except = [],
    ) {
        $this->except = $except;
    }

    /**
     * Let these events through to their listeners.
     *
     * @param array<int, string>|string $events
     */
    public function except(array|string $events): static
    {
        foreach ((array) $events as $event) {
            $this->except[] = $event;
        }

        return $this;
    }

    public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): mixed
    {
        $name = is_object($event) ? $event::class : $event;

        $this->dispatched[] = [
            'event' => $name,
            'payload' => is_object($event) ? [$event] : (array) $payload,
        ];

        if (in_array($name, $this->except, true) && $this->real !== null) {
            return $this->real->dispatch($event, $payload, $halt);
        }

        return $halt ? null : [];
    }

    public function until(string|object $event, mixed $payload = []): mixed
    {
        return $this->dispatch($event, $payload, true);
    }

    // ─── Looking at what happened ───────────────────────────

    /**
     * What was dispatched under an event name, optionally filtered.
     *
     * @param (Closure(mixed...): bool)|null $filter
     * @return array<int, array<int, mixed>> Each entry is that dispatch's payload.
     */
    public function dispatched(string $event, ?Closure $filter = null): array
    {
        $found = [];

        foreach ($this->dispatched as $record) {
            if ($record['event'] !== $event) {
                continue;
            }

            if ($filter === null || $filter(...$record['payload'])) {
                $found[] = $record['payload'];
            }
        }

        return $found;
    }

    public function hasDispatched(string $event): bool
    {
        return $this->dispatched($event) !== [];
    }

    // ─── Assertions ─────────────────────────────────────────

    /** @param (Closure(mixed...): bool)|int|null $callback */
    public function assertDispatched(string $event, Closure|int|null $callback = null): static
    {
        if (is_int($callback)) {
            return $this->assertDispatchedTimes($event, $callback);
        }

        Assert::assertNotEmpty(
            $this->dispatched($event, $callback),
            "The expected event [{$event}] was not dispatched."
        );

        return $this;
    }

    public function assertDispatchedTimes(string $event, int $times = 1): static
    {
        $count = count($this->dispatched($event));

        Assert::assertSame(
            $times,
            $count,
            "The event [{$event}] was dispatched {$count} times instead of {$times}."
        );

        return $this;
    }

    /** @param (Closure(mixed...): bool)|null $callback */
    public function assertNotDispatched(string $event, ?Closure $callback = null): static
    {
        Assert::assertEmpty(
            $this->dispatched($event, $callback),
            "The unexpected event [{$event}] was dispatched."
        );

        return $this;
    }

    public function assertNothingDispatched(): static
    {
        $names = array_map(static fn (array $r): string => $r['event'], $this->dispatched);

        Assert::assertEmpty(
            $this->dispatched,
            'Events were dispatched unexpectedly: ' . implode(', ', array_unique($names)) . '.'
        );

        return $this;
    }
}
