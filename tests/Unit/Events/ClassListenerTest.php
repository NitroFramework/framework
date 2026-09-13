<?php

namespace Tests\Unit\Events;

use Nitro\Events\Dispatcher;
use Nitro\Queue\Contracts\ShouldQueue;
use PHPUnit\Framework\TestCase;

/**
 * Listeners named by class, and listeners that should not run in the request.
 *
 * The point of naming a listener by class rather than passing a closure is that
 * registering it costs nothing: the class is resolved when its event actually
 * fires, so an application can declare every listener it has at boot without
 * constructing any of them.
 */
class ClassListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingListener::$heard = [];
        RecordingListener::$constructed = 0;
        InvokableListener::$heard = [];
        QueuedListener::$heard = [];
    }

    public function test_a_class_listener_handles_an_object_event(): void
    {
        $events = new Dispatcher();
        $events->listen(OrderPaid::class, RecordingListener::class);

        $events->dispatch(new OrderPaid('LP-2026-0042'));

        $this->assertSame(['LP-2026-0042'], RecordingListener::$heard);
    }

    public function test_a_class_listener_is_not_constructed_until_its_event_fires(): void
    {
        $events = new Dispatcher();
        $events->listen(OrderPaid::class, RecordingListener::class);

        $this->assertSame(0, RecordingListener::$constructed);

        $events->dispatch(new OrderPaid('LP-2026-0001'));

        $this->assertSame(1, RecordingListener::$constructed);
    }

    public function test_an_invokable_listener_needs_no_handle_method(): void
    {
        $events = new Dispatcher();
        $events->listen(OrderPaid::class, InvokableListener::class);

        $events->dispatch(new OrderPaid('LP-2026-0002'));

        $this->assertSame(['LP-2026-0002'], InvokableListener::$heard);
    }

    public function test_an_explicit_method_can_be_named(): void
    {
        $events = new Dispatcher();
        $events->listen(OrderPaid::class, RecordingListener::class . '@notify');

        $events->dispatch(new OrderPaid('LP-2026-0003'));

        $this->assertSame(['notified:LP-2026-0003'], RecordingListener::$heard);
    }

    public function test_a_closure_listener_still_works(): void
    {
        $heard = [];
        $events = new Dispatcher();
        $events->listen(OrderPaid::class, function (OrderPaid $event) use (&$heard) {
            $heard[] = $event->reference;
        });

        $events->dispatch(new OrderPaid('LP-2026-0004'));

        $this->assertSame(['LP-2026-0004'], $heard);
    }

    public function test_a_subscriber_registers_its_own_listeners(): void
    {
        $events = new Dispatcher();
        $events->subscribe(OrderSubscriber::class);

        $events->dispatch(new OrderPaid('LP-2026-0005'));

        $this->assertSame(['subscribed:LP-2026-0005'], RecordingListener::$heard);
    }

    // ─── Queued listeners ─────────────────────────────────

    public function test_a_queued_listener_runs_inline_when_there_is_no_container(): void
    {
        // Outside an application there is no queue to push to. Running it
        // in-process is better than silently doing nothing.
        $events = new Dispatcher();
        $events->listen(OrderPaid::class, QueuedListener::class);

        $events->dispatch(new OrderPaid('LP-2026-0006'));

        $this->assertSame(['LP-2026-0006'], QueuedListener::$heard);
    }

    public function test_a_queued_listener_cannot_veto(): void
    {
        // until() takes the first non-null response. A queued listener returns
        // null by construction: its answer would arrive in another process,
        // after the decision it was being asked about had already been made.
        $events = new Dispatcher();
        $events->listen('order.checking', QueuedListener::class);
        $events->listen('order.checking', fn () => 'the real answer');

        $this->assertSame('the real answer', $events->until('order.checking', 'payload'));
    }
}

// ─── Fixtures ─────────────────────────────────────────────

final class OrderPaid
{
    public function __construct(public string $reference) {}
}

class RecordingListener
{
    public static array $heard = [];
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function handle(OrderPaid $event): void
    {
        self::$heard[] = $event->reference;
    }

    public function notify(OrderPaid $event): void
    {
        self::$heard[] = 'notified:' . $event->reference;
    }

    public function subscribed(OrderPaid $event): void
    {
        self::$heard[] = 'subscribed:' . $event->reference;
    }
}

class InvokableListener
{
    public static array $heard = [];

    public function __invoke(OrderPaid $event): void
    {
        self::$heard[] = $event->reference;
    }
}

class QueuedListener implements ShouldQueue
{
    public static array $heard = [];

    public function handle(mixed $event): void
    {
        self::$heard[] = $event instanceof OrderPaid ? $event->reference : $event;
    }
}

class OrderSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(OrderPaid::class, RecordingListener::class . '@subscribed');
    }
}
