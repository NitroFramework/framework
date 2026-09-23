<?php

namespace Tests\Unit\Notifications;

use Nitro\Container\Container;
use Nitro\Events\Dispatcher;
use Nitro\Mail\Contracts\Mailer;
use Nitro\Notifications\AnonymousNotifiable;
use Nitro\Notifications\ChannelManager;
use Nitro\Notifications\Contracts\Channel;
use Nitro\Notifications\Events\NotificationFailed;
use Nitro\Notifications\Events\NotificationSending;
use Nitro\Notifications\Events\NotificationSent;
use Nitro\Notifications\Events\NotificationSkipped;
use Nitro\Notifications\Messages\MailMessage;
use Nitro\Notifications\Notification;
use Nitro\Notifications\NotificationSender;
use Nitro\Notifications\RoutesNotifications;
use PHPUnit\Framework\TestCase;

/** What the sender does around a channel, rather than inside one. */
class NotificationParityTest extends TestCase
{
    private Dispatcher $events;
    private ChannelManager $channels;
    private NotificationSender $sender;
    private RecordingChannel $channel;

    protected function setUp(): void
    {
        Container::setInstance(new Container());

        $this->events = new Dispatcher();
        $this->channel = new RecordingChannel();

        $this->channels = new ChannelManager(
            static fn (): Mailer => throw new \RuntimeException('not used'),
            $this->events,
        );

        $this->channels->set('recording', $this->channel);
        $this->channels->set('second', new RecordingChannel());

        $this->sender = $this->channels->sender();
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());
    }

    /** @var array<int, object> */
    private array $heard = [];

    private function listenFor(string $event): void
    {
        $this->events->listen($event, function (object $e): void {
            $this->heard[] = $e;
        });
    }

    private function heardOf(string $type): ?object
    {
        foreach ($this->heard as $event) {
            if ($event instanceof $type) {
                return $event;
            }
        }

        return null;
    }

    // ── The dispatcher ────────────────────────────────────────────────

    /**
     * Sending works without an application behind it.
     *
     * The sender reached for the global container to find an event
     * dispatcher, so a sender built by hand threw before it sent
     * anything.
     */
    public function test_a_sender_built_by_hand_can_send(): void
    {
        $this->sender->sendNow(new Person(), new RecordedNotification());

        $this->assertCount(1, $this->channel->sent);
    }

    // ── The shared identifier ─────────────────────────────────────────

    /**
     * Every channel of one delivery sees the same identifier.
     *
     * Each channel minted its own, so the row in a database and the
     * copy in an inbox had nothing in common to match them by.
     */
    public function test_one_identifier_is_shared_across_channels(): void
    {
        $this->sender->sendNow(new Person(), new TwoChannelNotification());

        $second = $this->channels->channel('second');

        $this->assertNotNull($this->channel->sent[0]->id);
        $this->assertSame($this->channel->sent[0]->id, $second->sent[0]->id);
    }

    public function test_two_deliveries_get_different_identifiers(): void
    {
        $this->sender->sendNow(new Person(), new RecordedNotification());
        $this->sender->sendNow(new Person(), new RecordedNotification());

        $this->assertNotSame($this->channel->sent[0]->id, $this->channel->sent[1]->id);
    }

    // ── Cancelling ────────────────────────────────────────────────────

    /**
     * A listener returning false from NotificationSending cancels it.
     *
     * The event fired and its answer was thrown away, so the documented
     * way to suppress sending did nothing at all.
     */
    public function test_a_listener_can_cancel_a_notification(): void
    {
        $this->events->listen(NotificationSending::class, static fn (): bool => false);

        $this->sender->sendNow(new Person(), new RecordedNotification());

        $this->assertSame([], $this->channel->sent);
    }

    public function test_a_cancelled_notification_says_it_was_skipped(): void
    {
        $this->listenFor(NotificationSkipped::class);
        $this->events->listen(NotificationSending::class, static fn (): bool => false);

        $this->sender->sendNow(new Person(), new RecordedNotification());

        $this->assertNotNull($this->heardOf(NotificationSkipped::class));
    }

    /** The notification itself can decline a channel. */
    public function test_should_send_can_decline_one_channel(): void
    {
        $this->sender->sendNow(new Person(), new SelectiveNotification());

        $this->assertSame([], $this->channel->sent, 'the first channel declined');
        $this->assertCount(1, $this->channels->channel('second')->sent);
    }

    // ── Failing ───────────────────────────────────────────────────────

    /**
     * A channel that throws is reported and the failure travels on.
     *
     * The sender caught it and moved to the next channel, so a mail
     * server that was refusing connections looked exactly like a
     * successful send from the call site.
     */
    public function test_a_failing_channel_reports_and_rethrows(): void
    {
        $this->listenFor(NotificationFailed::class);
        $this->channels->set('recording', new ThrowingChannel());

        try {
            $this->sender->sendNow(new Person(), new RecordedNotification());
            $this->fail('the exception must reach the caller');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotNull($this->heardOf(NotificationFailed::class));
    }

    // ── Events ────────────────────────────────────────────────────────

    public function test_a_sent_notification_says_so(): void
    {
        $this->listenFor(NotificationSent::class);

        $this->sender->sendNow(new Person(), new RecordedNotification());

        $sent = $this->heardOf(NotificationSent::class);

        $this->assertNotNull($sent);
        $this->assertSame('recording', $sent->channel);
    }

    // ── Channels ──────────────────────────────────────────────────────

    /** A notification naming no channel is not sent at all. */
    public function test_a_notification_with_no_channels_sends_nothing(): void
    {
        $this->sender->sendNow(new Person(), new SilentNotification());

        $this->assertSame([], $this->channel->sent);
    }

    /** The caller can name the channels instead of the notification. */
    public function test_the_caller_can_override_the_channels(): void
    {
        $this->sender->sendNow(new Person(), new SilentNotification(), ['recording']);

        $this->assertCount(1, $this->channel->sent);
    }

    /**
     * An anonymous notifiable is skipped on the database channel.
     *
     * It is an address, not a record, so there is nothing for a row to
     * belong to.
     */
    public function test_an_anonymous_notifiable_skips_the_database_channel(): void
    {
        $this->channels->set('database', $database = new RecordingChannel());

        $notifiable = (new AnonymousNotifiable())->route('recording', 'somewhere');

        $this->sender->sendNow($notifiable, new DatabaseAndRecordingNotification());

        $this->assertSame([], $database->sent);
        $this->assertCount(1, $this->channel->sent);
    }

    public function test_a_channel_of_your_own_can_be_added(): void
    {
        $this->channels->extend('carrier-pigeon', static fn (): Channel => new RecordingChannel());

        $this->assertInstanceOf(RecordingChannel::class, $this->channels->channel('carrier-pigeon'));
    }

    public function test_an_unknown_channel_is_refused_by_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/smoke-signal/');

        $this->channels->channel('smoke-signal');
    }

    public function test_the_default_channel_can_be_changed(): void
    {
        $this->assertSame('mail', $this->channels->deliversVia());

        $this->channels->deliverVia('recording');

        $this->assertSame('recording', $this->channels->deliversVia());
        $this->assertSame($this->channel, $this->channels->channel());
    }

    // ── Notifiables ───────────────────────────────────────────────────

    public function test_a_notifiable_is_routed_by_a_per_channel_method(): void
    {
        $this->assertSame('ada@example.com', (new Person())->routeNotificationFor('mail'));
        $this->assertSame('+1000', (new Person())->routeNotificationFor('sms'));
    }

    public function test_an_unrouted_channel_answers_null(): void
    {
        $this->assertNull((new Person())->routeNotificationFor('carrier-pigeon'));
    }

    // ── Message building ──────────────────────────────────────────────

    /** A line is added only when the condition holds. */
    public function test_conditional_lines_are_skipped_when_false(): void
    {
        $message = (new MailMessage())
            ->line('always')
            ->lineIf(false, 'never')
            ->lineIf(true, 'sometimes');

        $this->assertSame(['always', 'sometimes'], $message->introLines);
    }

    public function test_tags_and_metadata_are_carried(): void
    {
        $message = (new MailMessage())->tag('welcome')->metadata('user_id', 7)->priority(1);

        $this->assertSame(['welcome'], $message->tags);
        $this->assertSame(['user_id' => 7], $message->metadata);
        $this->assertSame(1, $message->priority);
    }

    /** Priority is clamped to the range a mail header allows. */
    public function test_priority_is_clamped(): void
    {
        $this->assertSame(1, (new MailMessage())->priority(0)->priority);
        $this->assertSame(5, (new MailMessage())->priority(9)->priority);
    }
}

// ── Notifiables ───────────────────────────────────────────────────────

class Person
{
    use RoutesNotifications;

    public string $email = 'ada@example.com';

    public function routeNotificationForSms(): string
    {
        return '+1000';
    }
}

// ── Notifications ─────────────────────────────────────────────────────

class RecordedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['recording'];
    }
}

class TwoChannelNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['recording', 'second'];
    }
}

class SilentNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return [];
    }
}

class DatabaseAndRecordingNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database', 'recording'];
    }
}

class SelectiveNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['recording', 'second'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $channel !== 'recording';
    }
}

// ── Channels ──────────────────────────────────────────────────────────

class RecordingChannel implements Channel
{
    /** @var array<int, Notification> */
    public array $sent = [];

    public function send(object $notifiable, Notification $notification): void
    {
        $this->sent[] = $notification;
    }
}

class ThrowingChannel implements Channel
{
    public function send(object $notifiable, Notification $notification): void
    {
        throw new \RuntimeException('the carrier is down');
    }
}
