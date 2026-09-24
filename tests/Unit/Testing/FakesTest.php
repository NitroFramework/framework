<?php

namespace Tests\Unit\Testing;

use Nitro\Cache\CacheFake;
use Nitro\Container\Container;
use Nitro\Events\Dispatcher;
use Nitro\Events\EventFake;
use Nitro\Mail\MailFake;
use Nitro\Mail\Message;
use Nitro\Notifications\Notification;
use Nitro\Notifications\NotificationFake;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Standing in for a service so a test can say what the code asked of it.
 *
 * Every layer has been audited and an application still could not write a test
 * saying "this request sent that notification" without a mail transport and a
 * queue worker behind it. These are what make the rest of the framework
 * assertable.
 */
class FakesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(new Container());
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());

        parent::tearDown();
    }

    // ─── Events ───────────────────────────────────────────

    /** Listeners not running is the point, not a side effect. */
    public function test_a_faked_dispatcher_records_and_does_not_run_listeners(): void
    {
        $events = new EventFake();

        $ran = false;

        $events->listen(FakedEvent::class, function () use (&$ran): void {
            $ran = true;
        });

        $events->dispatch(new FakedEvent(7));

        $events->assertDispatched(FakedEvent::class);
        $this->assertFalse($ran, 'a faked dispatcher must not run listeners');
    }

    public function test_an_event_can_be_matched_on_its_contents(): void
    {
        $events = new EventFake();

        $events->dispatch(new FakedEvent(7));

        $events->assertDispatched(FakedEvent::class, static fn (FakedEvent $e): bool => $e->id === 7);
        $events->assertNotDispatched(FakedEvent::class, static fn (FakedEvent $e): bool => $e->id === 99);
    }

    public function test_events_are_counted(): void
    {
        $events = new EventFake();

        $events->dispatch(new FakedEvent());
        $events->dispatch(new FakedEvent());

        $events->assertDispatchedTimes(FakedEvent::class, 2);
    }

    public function test_a_string_event_keeps_its_payload(): void
    {
        $events = new EventFake();

        $events->dispatch('report.ready', ['sales', 10]);

        $events->assertDispatched(
            'report.ready',
            static fn (string $name, int $rows): bool => $name === 'sales' && $rows === 10,
        );
    }

    /** Sometimes a listener is the thing under test. */
    public function test_a_named_event_still_reaches_its_listeners(): void
    {
        $real = new Dispatcher();

        $ran = false;

        $real->listen(FakedEvent::class, function () use (&$ran): void {
            $ran = true;
        });

        $events = new EventFake($real, [FakedEvent::class]);

        $events->dispatch(new FakedEvent());

        $this->assertTrue($ran, 'an excepted event should reach its listeners');
        $events->assertDispatched(FakedEvent::class);
    }

    public function test_nothing_dispatched_is_assertable(): void
    {
        (new EventFake())->assertNothingDispatched();

        $this->expectException(AssertionFailedError::class);

        (new EventFake())->assertDispatched(FakedEvent::class);
    }

    // ─── Mail ─────────────────────────────────────────────

    public function test_mail_is_recorded_rather_than_sent(): void
    {
        $mail = new MailFake();

        $mail->send((new Message())->to('ada@example.test')->subject('Hi')->text('body'));

        $mail->assertSent(Message::class);
        $mail->assertSentTimes(Message::class, 1);
        $mail->assertSentTo('ada@example.test');
    }

    public function test_raw_and_html_shortcuts_are_recorded(): void
    {
        $mail = new MailFake();

        $mail->raw('a@example.test', 'S', 'text');
        $mail->html('b@example.test', 'S', '<p>html</p>');

        $mail->assertSentTimes(Message::class, 2);
        $mail->assertSentTo('b@example.test');
    }

    public function test_nothing_sent_is_assertable(): void
    {
        (new MailFake())->assertNothingSent();

        $this->expectException(AssertionFailedError::class);

        (new MailFake())->assertSent(Message::class);
    }

    // ─── Notifications ────────────────────────────────────

    /**
     * Recorded per notifiable: the usual question is not whether it was sent
     * but whether it reached the right person, and one sent to everybody is a
     * mistake a count alone will not catch.
     */
    public function test_notifications_are_recorded_against_who_received_them(): void
    {
        $notifications = new NotificationFake();

        $one = new FakedNotifiable(1);
        $two = new FakedNotifiable(2);

        $notifications->send($one, new FakedNotification());

        $notifications->assertSentTo($one, FakedNotification::class);
        $notifications->assertNotSentTo($two, FakedNotification::class);
    }

    public function test_several_notifiables_each_get_a_record(): void
    {
        $notifications = new NotificationFake();

        $notifications->send([new FakedNotifiable(1), new FakedNotifiable(2)], new FakedNotification());

        $notifications->assertSentTimes(FakedNotification::class, 2);
    }

    /** A notification quietly losing a channel is invisible to a count. */
    public function test_the_channels_it_went_out_on_are_assertable(): void
    {
        $notifications = new NotificationFake();

        $user = new FakedNotifiable(1);

        $notifications->send($user, new FakedNotification());

        $notifications->assertSentOnChannel($user, FakedNotification::class, 'mail');

        $this->expectException(AssertionFailedError::class);

        $notifications->assertSentOnChannel($user, FakedNotification::class, 'sms');
    }

    public function test_a_queued_notification_is_recorded_too(): void
    {
        $notifications = new NotificationFake();

        $user = new FakedNotifiable(1);

        $notifications->queue($user, new FakedNotification());

        $notifications->assertSentTo($user, FakedNotification::class);
    }

    public function test_nothing_notified_is_assertable(): void
    {
        (new NotificationFake())->assertNothingSent();
    }

    // ─── Cache ────────────────────────────────────────────

    /**
     * Still a working cache, not only a recorder — code that caches almost
     * always reads back what it wrote.
     */
    public function test_a_faked_cache_still_stores_and_returns(): void
    {
        $cache = new CacheFake();

        $cache->put('report', ['rows' => 3], 60);

        $this->assertSame(['rows' => 3], $cache->get('report'));
    }

    public function test_what_was_cached_is_assertable_including_its_lifetime(): void
    {
        $cache = new CacheFake();

        $cache->put('report', 'x', 60);

        $cache->assertCached('report');
        $cache->assertCached('report', 60);
        $cache->assertNotCached('something.else');
    }

    public function test_forever_counts_as_cached(): void
    {
        $cache = new CacheFake();

        $cache->forever('settings', ['a' => 1]);

        $cache->assertCached('settings');
    }

    public function test_a_forget_is_recorded(): void
    {
        $cache = new CacheFake();

        $cache->put('k', 'v');
        $cache->forget('k');

        $cache->assertForgotten('k');
    }

    public function test_nothing_cached_is_assertable(): void
    {
        $cache = new CacheFake();

        $cache->get('never.written');

        $cache->assertNothingCached();
    }

    public function test_a_wrong_lifetime_fails(): void
    {
        $cache = new CacheFake();

        $cache->put('report', 'x', 60);

        $this->expectException(AssertionFailedError::class);

        $cache->assertCached('report', 120);
    }
}

class FakedEvent
{
    public function __construct(public int $id = 0)
    {
    }
}

class FakedNotifiable
{
    public function __construct(public int $id = 0)
    {
    }
}

class FakedNotification extends Notification
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
