<?php

namespace Tests\Unit\Events;

use Nitro\Events\Contracts\ShouldDispatchAfterCommit;
use Nitro\Events\Contracts\ShouldHandleEventsAfterCommit;
use Nitro\Events\Dispatcher;
use Nitro\Events\NullDispatcher;
use PHPUnit\Framework\TestCase;

/** How a listener is found and what it is handed. */
class DispatcherParityTest extends TestCase
{
    private Dispatcher $events;

    protected function setUp(): void
    {
        $this->events = new Dispatcher();
        ParityRecorder::reset();
    }

    // ── Wildcards ─────────────────────────────────────────────────────

    /** A wildcard listener is told which event it got. */
    public function test_a_wildcard_listener_receives_the_event_name_first(): void
    {
        $seen = null;

        $this->events->listen('report.*', function (string $event, array $payload) use (&$seen): void {
            $seen = [$event, $payload];
        });

        $this->events->dispatch('report.daily', ['rows' => 3]);

        $this->assertSame(['report.daily', ['rows' => 3]], $seen);
    }

    public function test_a_bare_star_matches_everything(): void
    {
        $seen = [];

        $this->events->listen('*', function (string $event) use (&$seen): void {
            $seen[] = $event;
        });

        $this->events->dispatch('one');
        $this->events->dispatch('two');

        $this->assertSame(['one', 'two'], $seen);
    }

    public function test_a_wildcard_pattern_can_be_forgotten(): void
    {
        $this->events->listen('report.*', static fn (): null => null);

        $this->assertTrue($this->events->hasListeners('report.daily'));

        $this->events->forget('report.*');

        $this->assertFalse($this->events->hasListeners('report.daily'));
    }

    /** A pattern registered after the first match is still matched. */
    public function test_the_wildcard_cache_is_dropped_when_a_pattern_is_added(): void
    {
        $seen = [];

        $this->events->listen('a.*', function () use (&$seen): void { $seen[] = 'first'; });
        $this->events->dispatch('a.b');

        $this->events->listen('a.*', function () use (&$seen): void { $seen[] = 'second'; });
        $this->events->dispatch('a.b');

        $this->assertSame(['first', 'first', 'second'], $seen);
    }

    // ── Interfaces ────────────────────────────────────────────────────

    /** A listener registered against an interface hears every implementor. */
    public function test_an_interface_listener_hears_every_implementor(): void
    {
        $this->events->listen(Notable::class, function (object $event): void {
            ParityRecorder::$ran[] = $event::class;
        });

        $this->events->dispatch(new Sale());
        $this->events->dispatch(new Refund());

        $this->assertSame([Sale::class, Refund::class], ParityRecorder::$ran);
    }

    public function test_an_interface_listener_runs_alongside_the_class_listener(): void
    {
        $this->events->listen(Notable::class, function (): void { ParityRecorder::$ran[] = 'interface'; });
        $this->events->listen(Sale::class, function (): void { ParityRecorder::$ran[] = 'class'; });

        $this->events->dispatch(new Sale());

        $this->assertSame(['class', 'interface'], ParityRecorder::$ran);
    }

    // ── Closure type hints ────────────────────────────────────────────

    /** A closure on its own names its event by type hint. */
    public function test_a_closure_names_its_event_by_type_hint(): void
    {
        $this->events->listen(function (Sale $event): void {
            ParityRecorder::$ran[] = 'sale';
        });

        $this->events->dispatch(new Sale());

        $this->assertSame(['sale'], ParityRecorder::$ran);
    }

    public function test_a_union_hint_registers_for_each_event(): void
    {
        $this->events->listen(function (Sale|Refund $event): void {
            ParityRecorder::$ran[] = $event::class;
        });

        $this->events->dispatch(new Refund());
        $this->events->dispatch(new Sale());

        $this->assertSame([Refund::class, Sale::class], ParityRecorder::$ran);
    }

    // ── Pushed events ─────────────────────────────────────────────────

    /** A pushed event does not fire until it is flushed. */
    public function test_a_pushed_event_waits_for_the_flush(): void
    {
        $this->events->listen('mail.send', function (string $to): void {
            ParityRecorder::$ran[] = $to;
        });

        $this->events->push('mail.send', ['ada@example.com']);

        $this->assertSame([], ParityRecorder::$ran, 'nothing fires on push');

        $this->events->flush('mail.send');

        $this->assertSame(['ada@example.com'], ParityRecorder::$ran);
    }

    public function test_pushed_events_can_be_dropped_unsent(): void
    {
        $this->events->listen('mail.send', function (): void { ParityRecorder::$ran[] = 'sent'; });

        $this->events->push('mail.send', ['ada@example.com']);
        $this->events->forgetPushed();
        $this->events->flush('mail.send');

        $this->assertSame([], ParityRecorder::$ran);
    }

    /** Dropping the pushed events leaves the real listeners alone. */
    public function test_forgetting_pushed_events_keeps_the_listeners(): void
    {
        $this->events->listen('mail.send', static fn (): null => null);

        $this->events->push('mail.send');
        $this->events->forgetPushed();

        $this->assertTrue($this->events->hasListeners('mail.send'));
    }

    // ── Deferring ─────────────────────────────────────────────────────

    /** Events raised inside defer() are held until it returns. */
    public function test_deferred_events_fire_after_the_block(): void
    {
        $this->events->listen('step', function (int $n): void {
            ParityRecorder::$ran[] = $n;
        });

        $this->events->defer(function (): void {
            $this->events->dispatch('step', [1]);
            $this->events->dispatch('step', [2]);

            ParityRecorder::$ran[] = 'inside';
        });

        $this->assertSame(['inside', 1, 2], ParityRecorder::$ran);
    }

    public function test_a_throwing_block_dispatches_nothing(): void
    {
        $this->events->listen('step', function (): void { ParityRecorder::$ran[] = 'fired'; });

        try {
            $this->events->defer(function (): void {
                $this->events->dispatch('step');

                throw new \RuntimeException('no');
            });
            $this->fail('the exception must propagate');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], ParityRecorder::$ran);
    }

    public function test_only_the_named_events_are_deferred(): void
    {
        $this->events->listen('held', function (): void { ParityRecorder::$ran[] = 'held'; });
        $this->events->listen('free', function (): void { ParityRecorder::$ran[] = 'free'; });

        $this->events->defer(function (): void {
            $this->events->dispatch('held');
            $this->events->dispatch('free');
        }, ['held']);

        $this->assertSame(['free', 'held'], ParityRecorder::$ran);
    }

    // ── After commit ──────────────────────────────────────────────────

    /** An event that belongs to a transaction waits for the commit. */
    public function test_an_after_commit_event_waits_for_the_commit(): void
    {
        $transactions = new FakeTransactions(level: 1);

        $this->events->setTransactionManagerResolver(static fn (): object => $transactions);
        $this->events->listen(CommittedSale::class, function (): void {
            ParityRecorder::$ran[] = 'fired';
        });

        $this->events->dispatch(new CommittedSale());

        $this->assertSame([], ParityRecorder::$ran, 'nothing fires inside the transaction');

        $transactions->commit();

        $this->assertSame(['fired'], ParityRecorder::$ran);
    }

    /** With nothing tracking a transaction there is nothing to wait for. */
    public function test_an_after_commit_event_fires_at_once_without_a_transaction(): void
    {
        $this->events->listen(CommittedSale::class, function (): void {
            ParityRecorder::$ran[] = 'fired';
        });

        $this->events->dispatch(new CommittedSale());

        $this->assertSame(['fired'], ParityRecorder::$ran);
    }

    /** A listener can make the same choice for an event that did not. */
    public function test_a_listener_can_ask_to_wait_for_the_commit(): void
    {
        $transactions = new FakeTransactions(level: 1);

        $this->events->setTransactionManagerResolver(static fn (): object => $transactions);
        $this->events->listen(Sale::class, WaitingListener::class);

        $this->events->dispatch(new Sale());

        $this->assertSame([], ParityRecorder::$ran);

        $transactions->commit();

        $this->assertSame(['waited'], ParityRecorder::$ran);
    }

    // ── Reading the map ───────────────────────────────────────────────

    public function test_the_listeners_for_an_event_can_be_read(): void
    {
        $this->events->listen('e', static fn (): null => null);
        $this->events->listen('e.*', static fn (): null => null);

        $this->assertCount(1, $this->events->getListeners('e'));
        $this->assertArrayHasKey('e', $this->events->getRawListeners());
    }

    public function test_wildcard_listeners_are_reported_separately(): void
    {
        $this->events->listen('e.*', static fn (): null => null);

        $this->assertTrue($this->events->hasWildcardListeners('e.thing'));
        $this->assertFalse($this->events->hasWildcardListeners('other'));
    }

    // ── Subscribers ───────────────────────────────────────────────────

    /** A subscriber may list its listeners instead of registering them. */
    public function test_a_subscriber_can_return_its_listener_map(): void
    {
        $this->events->subscribe(new MappingSubscriber());

        $this->events->dispatch(new Sale());

        $this->assertSame(['mapped'], ParityRecorder::$ran);
    }

    public function test_a_subscriber_can_still_register_directly(): void
    {
        $this->events->subscribe(new RegisteringSubscriber());

        $this->events->dispatch(new Sale());

        $this->assertSame(['registered'], ParityRecorder::$ran);
    }

    // ── The null dispatcher ───────────────────────────────────────────

    /** A muted dispatcher still takes registrations. */
    public function test_a_null_dispatcher_registers_but_does_not_dispatch(): void
    {
        $muted = new NullDispatcher($this->events);

        $muted->listen('e', function (): void { ParityRecorder::$ran[] = 'fired'; });

        $this->assertNull($muted->dispatch('e'));
        $this->assertSame([], ParityRecorder::$ran);

        $this->assertTrue($muted->hasListeners('e'), 'the registration reached the real one');

        $this->events->dispatch('e');

        $this->assertSame(['fired'], ParityRecorder::$ran);
    }

    public function test_a_null_dispatcher_forwards_what_is_not_a_dispatch(): void
    {
        $muted = new NullDispatcher($this->events);

        $muted->listen('e.*', static fn (): null => null);

        $this->assertTrue($muted->hasWildcardListeners('e.thing'));
        $this->assertSame($this->events, $muted->getDispatcher());
    }
}

// ── Events ────────────────────────────────────────────────────────────

interface Notable {}

class Sale implements Notable {}

class Refund implements Notable {}

class CommittedSale implements ShouldDispatchAfterCommit {}

// ── Listeners ─────────────────────────────────────────────────────────

class WaitingListener implements ShouldHandleEventsAfterCommit
{
    public function handle(object $event): void
    {
        ParityRecorder::$ran[] = 'waited';
    }
}

class MappingSubscriber
{
    /** @return array<string, string> */
    public function subscribe(object $events): array
    {
        return [Sale::class => 'onSale'];
    }

    public function onSale(object $event): void
    {
        ParityRecorder::$ran[] = 'mapped';
    }
}

class RegisteringSubscriber
{
    public function subscribe(object $events): void
    {
        $events->listen(Sale::class, function (): void {
            ParityRecorder::$ran[] = 'registered';
        });
    }
}

// ── Doubles ───────────────────────────────────────────────────────────

class ParityRecorder
{
    /** @var array<int, mixed> */
    public static array $ran = [];

    public static function reset(): void
    {
        self::$ran = [];
    }
}

/** Stands in for the transaction tracker, without a database. */
class FakeTransactions
{
    /** @var array<int, \Closure> */
    private array $callbacks = [];

    public function __construct(private int $level = 0) {}

    public function addCallback(\Closure $callback): void
    {
        if ($this->level === 0) {
            $callback();

            return;
        }

        $this->callbacks[] = $callback;
    }

    public function commit(): void
    {
        $this->level--;

        if ($this->level > 0) {
            return;
        }

        foreach ($this->callbacks as $callback) {
            $callback();
        }

        $this->callbacks = [];
    }
}
