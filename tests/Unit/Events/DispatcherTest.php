<?php

namespace Tests\Unit\Events;

use Nitro\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

class SampleEvent
{
    public function __construct(public string $name)
    {
    }
}

/** The upgraded dispatcher: return values, until()/halting, object events, wildcards. */
class DispatcherTest extends TestCase
{
    public function test_dispatch_returns_listener_responses(): void
    {
        $d = new Dispatcher();
        $d->listen('e', fn () => 'a');
        $d->listen('e', fn () => 'b');

        $this->assertSame(['a', 'b'], $d->dispatch('e'));
    }

    public function test_payload_is_passed_as_single_argument(): void
    {
        $d = new Dispatcher();
        $seen = null;
        $d->listen('e', function ($payload) use (&$seen) { $seen = $payload; });

        $d->dispatch('e', ['k' => 'v']);
        $this->assertSame(['k' => 'v'], $seen);
    }

    public function test_until_halts_at_first_non_null_response(): void
    {
        $d = new Dispatcher();
        $calls = 0;
        $d->listen('e', function () use (&$calls) { $calls++; return null; });
        $d->listen('e', function () use (&$calls) { $calls++; return 'stop'; });
        $d->listen('e', function () use (&$calls) { $calls++; return 'never'; });

        $this->assertSame('stop', $d->until('e'));
        $this->assertSame(2, $calls, 'until() must not call listeners past the halting one.');
    }

    public function test_false_response_stops_propagation(): void
    {
        $d = new Dispatcher();
        $reached = false;
        $d->listen('e', fn () => false);
        $d->listen('e', function () use (&$reached) { $reached = true; });

        $d->dispatch('e');
        $this->assertFalse($reached, 'A false response halts the remaining listeners.');
    }

    public function test_object_event_uses_class_name_and_object_payload(): void
    {
        $d = new Dispatcher();
        $seen = null;
        $d->listen(SampleEvent::class, function ($event) use (&$seen) { $seen = $event; });

        $event = new SampleEvent('hi');
        $d->dispatch($event);

        $this->assertSame($event, $seen);
        $this->assertSame('hi', $seen->name);
    }

    public function test_wildcard_pattern_matches(): void
    {
        $d = new Dispatcher();
        $hits = [];
        $d->listen('model.*', function () use (&$hits) { $hits[] = 'wild'; });
        $d->listen('model.saved', function () use (&$hits) { $hits[] = 'exact'; });

        $d->dispatch('model.saved');
        $d->dispatch('model.deleted');
        $d->dispatch('other.thing');

        // saved → exact + wild; deleted → wild; other → none
        $this->assertSame(['exact', 'wild', 'wild'], $hits);
    }

    public function test_global_wildcard_matches_everything(): void
    {
        $d = new Dispatcher();
        $count = 0;
        $d->listen('*', function () use (&$count) { $count++; });

        $d->dispatch('a');
        $d->dispatch('b');
        $this->assertSame(2, $count);
    }

    public function test_disabled_dispatcher_is_a_no_op(): void
    {
        $d = new Dispatcher();
        $called = false;
        $d->listen('e', function () use (&$called) { $called = true; });

        $d->disable();
        $this->assertSame([], $d->dispatch('e'));
        $this->assertFalse($called);
    }
}
