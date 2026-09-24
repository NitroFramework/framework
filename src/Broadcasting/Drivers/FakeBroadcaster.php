<?php

namespace Nitro\Broadcasting\Drivers;

use Closure;
use Nitro\Broadcasting\Contracts\Broadcaster;
use PHPUnit\Framework\Assert;

/**
 * A broadcaster that records what it was given.
 *
 *     Broadcast::fake();
 *
 *     $this->post('/messages', [...]);
 *
 *     Broadcast::assertBroadcast(MessageSent::class, 'private-room.1');
 *
 * Without this a test covering code that broadcasts either needs a socket
 * server running, which makes the test about the server, or asserts nothing,
 * which is what usually happens — and broadcasting silently stops working.
 */
class FakeBroadcaster implements Broadcaster
{
    /** @var array<int, array{channels: array<int, string>, event: string, payload: array<string, mixed>}> */
    protected array $sent = [];

    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        $this->sent[] = [
            'channels' => array_values($channels),
            'event' => $event,
            'payload' => $payload,
        ];
    }

    /**
     * Everything that was broadcast, in order.
     *
     * @return array<int, array{channels: array<int, string>, event: string, payload: array<string, mixed>}>
     */
    public function broadcasts(): array
    {
        return $this->sent;
    }

    /**
     * What was broadcast under an event name, optionally filtered.
     *
     * @param (Closure(array<string, mixed>, array<int, string>): bool)|null $filter
     * @return array<int, array{channels: array<int, string>, event: string, payload: array<string, mixed>}>
     */
    public function broadcastsOf(string $event, ?Closure $filter = null): array
    {
        $found = [];

        foreach ($this->sent as $record) {
            if ($record['event'] !== $event) {
                continue;
            }

            if ($filter === null || $filter($record['payload'], $record['channels'])) {
                $found[] = $record;
            }
        }

        return $found;
    }

    /** Forget everything recorded so far. */
    public function flush(): static
    {
        $this->sent = [];

        return $this;
    }

    // ─── Assertions ─────────────────────────────────────────

    /**
     * @param (Closure(array<string, mixed>, array<int, string>): bool)|null $filter
     */
    public function assertBroadcast(string $event, ?string $channel = null, ?Closure $filter = null): static
    {
        $found = $this->broadcastsOf($event, $filter);

        Assert::assertNotEmpty($found, "The event [{$event}] was not broadcast.");

        if ($channel !== null) {
            $channels = array_merge(...array_map(
                static fn (array $record): array => $record['channels'],
                $found,
            ));

            Assert::assertContains(
                $channel,
                $channels,
                "The event [{$event}] was broadcast, but not on [{$channel}]."
            );
        }

        return $this;
    }

    public function assertNotBroadcast(string $event, ?Closure $filter = null): static
    {
        Assert::assertEmpty(
            $this->broadcastsOf($event, $filter),
            "The event [{$event}] was broadcast unexpectedly."
        );

        return $this;
    }

    public function assertBroadcastTimes(string $event, int $times = 1): static
    {
        $count = count($this->broadcastsOf($event));

        Assert::assertSame(
            $times,
            $count,
            "The event [{$event}] was broadcast {$count} times instead of {$times}."
        );

        return $this;
    }

    public function assertNothingBroadcast(): static
    {
        $names = array_map(static fn (array $record): string => $record['event'], $this->sent);

        Assert::assertEmpty(
            $this->sent,
            'Events were broadcast unexpectedly: ' . implode(', ', array_unique($names)) . '.'
        );

        return $this;
    }

    /**
     * Assert the broadcast excluded the connection that caused it.
     *
     * The sender's own client already rendered the thing, so a broadcast that
     * comes back to them shows it twice — a bug that only appears with two
     * browsers open, which is to say in production.
     */
    public function assertBroadcastToOthers(string $event): static
    {
        $found = $this->broadcastsOf($event);

        Assert::assertNotEmpty($found, "The event [{$event}] was not broadcast.");

        Assert::assertNotEmpty(
            $found[0]['payload']['socket'] ?? null,
            "The event [{$event}] was broadcast to everyone, including the sender."
        );

        return $this;
    }
}
