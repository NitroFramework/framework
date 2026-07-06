<?php

namespace Tests\Unit\Events;

use Nitro\Events\Concerns\DispatchesEvents;
use Nitro\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The hot-path event helpers must skip dispatch entirely (and skip building
 * the payload, when using eventLazy) if no listeners are bound.
 */
class DispatchesEventsLazyTest extends TestCase
{
    protected function makeEmitter(?Dispatcher $dispatcher): object
    {
        $emitter = new class {
            use DispatchesEvents {
                event as public;
                eventLazy as public;
            }
        };
        if ($dispatcher !== null) {
            $emitter->setDispatcher($dispatcher);
        }
        return $emitter;
    }

    public function test_event_short_circuits_when_dispatcher_unset(): void
    {
        $emitter = $this->makeEmitter(null);
        // Should not throw, should not require a payload to be built.
        $emitter->event('any.event', ['payload' => 1]);
        $this->addToAssertionCount(1);
    }

    public function test_event_short_circuits_when_no_listeners_bound(): void
    {
        $dispatcher = new Dispatcher();
        $emitter = $this->makeEmitter($dispatcher);

        $emitter->event('unwatched.event', ['payload' => 1]);
        $this->addToAssertionCount(1);
    }

    public function test_event_lazy_skips_building_payload_when_no_listeners(): void
    {
        $dispatcher = new Dispatcher();
        $emitter = $this->makeEmitter($dispatcher);

        $built = false;
        $emitter->eventLazy('unwatched.event', function () use (&$built) {
            $built = true;
            return ['payload' => 1];
        });

        $this->assertFalse($built, 'Lazy payload builder must not run when no listener is bound.');
    }

    public function test_event_lazy_builds_payload_when_listener_present(): void
    {
        $dispatcher = new Dispatcher();
        $emitter = $this->makeEmitter($dispatcher);

        $received = null;
        $dispatcher->listen('watched.event', function ($data) use (&$received) {
            $received = $data;
        });

        $emitter->eventLazy('watched.event', fn() => ['payload' => 42]);

        // Dispatcher injects _event into the payload before calling listeners.
        $this->assertSame(42, $received['payload']);
        $this->assertSame('watched.event', $received['_event']);
    }
}
