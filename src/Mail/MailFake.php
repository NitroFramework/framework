<?php

namespace Nitro\Mail;

use Closure;
use PHPUnit\Framework\Assert;

/**
 * A mailer that records what it was asked to send.
 *
 *     Mail::fake();
 *
 *     $this->post('/orders/42/ship');
 *
 *     Mail::assertSent(ShippedMail::class);
 *
 * Without this a test covering code that mails has two bad options: send for
 * real, which needs a transport and puts mail in someone's inbox, or assert
 * nothing — which is what usually happens, and is how mail quietly stops
 * working.
 */
class MailFake extends MailManager
{
    /** @var array<int, array{mailable: object, recipients: array<int, mixed>, queued: bool}> */
    protected array $sent = [];

    // ─── Recording instead of sending ───────────────────────

    public function send(Message|Mailable $message): void
    {
        $this->record($message, [], queued: false);
    }

    public function queue(Mailable $mailable): void
    {
        $this->record($mailable, [], queued: true);
    }

    public function later(int $delay, Mailable $mailable): void
    {
        $this->record($mailable, [], queued: true);
    }

    // to(), cc(), bcc() and usingMailer() are the parent's: each returns a
    // PendingMail holding this manager, and PendingMail settles by calling
    // send() or queue() back on it — both of which are recorded above.

    public function raw(string $to, string $subject, string $text): void
    {
        $this->record((new Message())->to($to)->subject($subject)->text($text), [$to], queued: false);
    }

    public function html(string $to, string $subject, string $html): void
    {
        $this->record((new Message())->to($to)->subject($subject)->html($html), [$to], queued: false);
    }

    /** @param array<int, mixed> $recipients */
    protected function record(object $mailable, array $recipients, bool $queued): void
    {
        $this->sent[] = [
            'mailable' => $mailable,
            'recipients' => $recipients === [] ? $this->recipientsOf($mailable) : $recipients,
            'queued' => $queued,
        ];
    }

    /**
     * Who a message is addressed to, as plain strings.
     *
     * A Message carries its own recipients; a Mailable has not been filled in
     * yet at the point it is queued, so it has none to report.
     *
     * @return array<int, string>
     */
    protected function recipientsOf(object $mailable): array
    {
        if (! $mailable instanceof Message) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $address): string => is_array($address)
                ? (string) ($address['address'] ?? $address[0] ?? '')
                : (string) $address,
            $mailable->to,
        ));
    }

    // ─── Looking at what happened ───────────────────────────

    /**
     * The messages of a class that were sent, optionally filtered.
     *
     * @param (Closure(object): bool)|null $filter
     * @return array<int, object>
     */
    public function sent(string $mailable, ?Closure $filter = null): array
    {
        $found = [];

        foreach ($this->sent as $record) {
            if (! $record['mailable'] instanceof $mailable) {
                continue;
            }

            if ($filter === null || $filter($record['mailable'])) {
                $found[] = $record['mailable'];
            }
        }

        return $found;
    }

    /** @return array<int, object> */
    public function queued(string $mailable): array
    {
        $found = [];

        foreach ($this->sent as $record) {
            if ($record['queued'] && $record['mailable'] instanceof $mailable) {
                $found[] = $record['mailable'];
            }
        }

        return $found;
    }

    // ─── Assertions ─────────────────────────────────────────

    /** @param (Closure(object): bool)|int|null $callback */
    public function assertSent(string $mailable, Closure|int|null $callback = null): static
    {
        if (is_int($callback)) {
            return $this->assertSentTimes($mailable, $callback);
        }

        Assert::assertNotEmpty(
            $this->sent($mailable, $callback),
            "The expected mailable [{$mailable}] was not sent."
        );

        return $this;
    }

    public function assertSentTimes(string $mailable, int $times = 1): static
    {
        $count = count($this->sent($mailable));

        Assert::assertSame(
            $times,
            $count,
            "The mailable [{$mailable}] was sent {$count} times instead of {$times}."
        );

        return $this;
    }

    /** @param (Closure(object): bool)|null $callback */
    public function assertNotSent(string $mailable, ?Closure $callback = null): static
    {
        Assert::assertEmpty(
            $this->sent($mailable, $callback),
            "The unexpected mailable [{$mailable}] was sent."
        );

        return $this;
    }

    public function assertNothingSent(): static
    {
        $names = array_map(static fn (array $r): string => $r['mailable']::class, $this->sent);

        Assert::assertEmpty(
            $this->sent,
            'Mail was sent unexpectedly: ' . implode(', ', array_unique($names)) . '.'
        );

        return $this;
    }

    public function assertQueued(string $mailable): static
    {
        Assert::assertNotEmpty($this->queued($mailable), "The mailable [{$mailable}] was not queued.");

        return $this;
    }

    /** Assert a message went to an address, whatever class it was. */
    public function assertSentTo(string $address, ?string $mailable = null): static
    {
        foreach ($this->sent as $record) {
            if ($mailable !== null && ! $record['mailable'] instanceof $mailable) {
                continue;
            }

            if (in_array($address, $record['recipients'], true)) {
                Assert::assertTrue(true);

                return $this;
            }
        }

        Assert::fail("No mail was sent to [{$address}].");
    }
}
