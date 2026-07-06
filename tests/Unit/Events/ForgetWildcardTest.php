<?php

namespace Tests\Unit\Events;

use Nitro\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * forget('*') must remove wildcard listeners — they live in a separate bag, so
 * unsetting $listeners['*'] left them firing.
 */
class ForgetWildcardTest extends TestCase
{
    public function test_forget_star_removes_wildcard_listeners(): void
    {
        $d = new Dispatcher();
        $hits = 0;
        $d->listen('*', function () use (&$hits) {
            $hits++;
        });

        $d->dispatch('user.created');
        $this->assertSame(1, $hits);

        $d->forget('*');
        $d->dispatch('user.created');
        $this->assertSame(1, $hits, 'wildcard listener must not fire after forget(*)');
        $this->assertFalse($d->hasListeners('user.created'));
    }

    public function test_forget_named_event_still_works(): void
    {
        $d = new Dispatcher();
        $hits = 0;
        $d->listen('ping', function () use (&$hits) {
            $hits++;
        });

        $d->forget('ping');
        $d->dispatch('ping');

        $this->assertSame(0, $hits);
    }
}
