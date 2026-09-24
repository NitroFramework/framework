<?php

namespace Nitro\Notifications;

use Closure;
use PHPUnit\Framework\Assert;

/**
 * A sender that records notifications instead of delivering them.
 *
 *     Notification::fake();
 *
 *     $this->post('/orders/42/ship');
 *
 *     Notification::assertSentTo($user, OrderShipped::class);
 *
 * Recorded per notifiable, because that is what the assertions are usually
 * about: not "was it sent" but "did it reach the right person" — and a
 * notification sent to everyone when it should have gone to one is a mistake
 * a count alone will not catch.
 */
class NotificationFake extends NotificationSender
{
    /** @var array<int, array{notifiable: object, notification: Notification, channels: array<int, string>}> */
    protected array $sent = [];

    public function __construct()
    {
        // The real sender needs a channel manager to deliver through. This one
        // delivers nothing, so it is built with nothing.
    }

    // ─── Recording instead of sending ───────────────────────

    public function send(object|iterable $notifiables, Notification $notification, ?array $channels = null): void
    {
        $this->sendNow($notifiables, $notification, $channels);
    }

    public function sendNow(object|iterable $notifiables, Notification $notification, ?array $channels = null): void
    {
        foreach ($this->notifiables($notifiables) as $notifiable) {
            $this->sent[] = [
                'notifiable' => $notifiable,
                'notification' => $notification,
                'channels' => $channels ?? $this->channelsFor($notifiable, $notification),
            ];
        }
    }

    public function queue(object|iterable $notifiables, Notification $notification, ?array $channels = null): void
    {
        $this->sendNow($notifiables, $notification, $channels);
    }

    /**
     * What the notification says it goes out on, when it can be asked.
     *
     * @return array<int, string>
     */
    protected function channelsFor(object $notifiable, Notification $notification): array
    {
        try {
            return (array) $notification->via($notifiable);
        } catch (\Throwable) {
            // A via() that reaches for something a faked run does not have is
            // not a reason to fail the test.
            return [];
        }
    }

    /** @return array<int, object> */
    protected function notifiables(object|iterable $notifiables): array
    {
        if (is_iterable($notifiables)) {
            $found = [];

            foreach ($notifiables as $notifiable) {
                if (is_object($notifiable)) {
                    $found[] = $notifiable;
                }
            }

            return $found;
        }

        return [$notifiables];
    }

    // ─── Looking at what happened ───────────────────────────

    /**
     * What a notifiable received of a class, optionally filtered.
     *
     * @param (Closure(Notification, array<int, string>): bool)|null $filter
     * @return array<int, Notification>
     */
    public function sentTo(object $notifiable, string $notification, ?Closure $filter = null): array
    {
        $found = [];

        foreach ($this->sent as $record) {
            if ($record['notifiable'] !== $notifiable || ! $record['notification'] instanceof $notification) {
                continue;
            }

            if ($filter === null || $filter($record['notification'], $record['channels'])) {
                $found[] = $record['notification'];
            }
        }

        return $found;
    }

    /** @return array<int, Notification> */
    public function sent(string $notification): array
    {
        $found = [];

        foreach ($this->sent as $record) {
            if ($record['notification'] instanceof $notification) {
                $found[] = $record['notification'];
            }
        }

        return $found;
    }

    // ─── Assertions ─────────────────────────────────────────

    /** @param (Closure(Notification, array<int, string>): bool)|int|null $callback */
    public function assertSentTo(object $notifiable, string $notification, Closure|int|null $callback = null): static
    {
        if (is_int($callback)) {
            return $this->assertSentToTimes($notifiable, $notification, $callback);
        }

        Assert::assertNotEmpty(
            $this->sentTo($notifiable, $notification, $callback),
            "The expected notification [{$notification}] did not reach that notifiable."
        );

        return $this;
    }

    public function assertSentToTimes(object $notifiable, string $notification, int $times = 1): static
    {
        $count = count($this->sentTo($notifiable, $notification));

        Assert::assertSame(
            $times,
            $count,
            "The notification [{$notification}] reached that notifiable {$count} times instead of {$times}."
        );

        return $this;
    }

    /** @param (Closure(Notification, array<int, string>): bool)|null $callback */
    public function assertNotSentTo(object $notifiable, string $notification, ?Closure $callback = null): static
    {
        Assert::assertEmpty(
            $this->sentTo($notifiable, $notification, $callback),
            "The unexpected notification [{$notification}] reached that notifiable."
        );

        return $this;
    }

    public function assertSentTimes(string $notification, int $times = 1): static
    {
        $count = count($this->sent($notification));

        Assert::assertSame(
            $times,
            $count,
            "The notification [{$notification}] was sent {$count} times instead of {$times}."
        );

        return $this;
    }

    public function assertNothingSent(): static
    {
        $names = array_map(static fn (array $r): string => $r['notification']::class, $this->sent);

        Assert::assertEmpty(
            $this->sent,
            'Notifications were sent unexpectedly: ' . implode(', ', array_unique($names)) . '.'
        );

        return $this;
    }

    /**
     * Assert it went out on a particular channel.
     *
     * Beyond what Laravel offers, and worth having: a notification quietly
     * losing a channel — dropping 'broadcast' so the bell badge stops
     * appearing — is invisible to an assertion that only asks whether it was
     * sent at all.
     */
    public function assertSentOnChannel(object $notifiable, string $notification, string $channel): static
    {
        foreach ($this->sent as $record) {
            if ($record['notifiable'] !== $notifiable || ! $record['notification'] instanceof $notification) {
                continue;
            }

            if (in_array($channel, $record['channels'], true)) {
                Assert::assertTrue(true);

                return $this;
            }
        }

        Assert::fail("The notification [{$notification}] did not go out on the [{$channel}] channel.");
    }
}
