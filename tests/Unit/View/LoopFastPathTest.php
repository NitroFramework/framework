<?php

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Nitro\View\Engine\Concerns\ManagesLoops;
use Nitro\View\Engine\RenderContext;

/**
 * Verifies the loop hot-path: the same stdClass frame is mutated in place
 * across iterations rather than rebuilt with array_merge per row.
 *
 * Uses an anonymous class that pulls in ManagesLoops so the trait can be
 * exercised in isolation. Loop state now lives on the RenderContext, so the
 * double exposes one (as the real ViewRenderer does).
 */
class LoopFastPathTest extends TestCase
{
    private function loops(): object
    {
        return new class {
            use ManagesLoops;
            public RenderContext $context;
            public function __construct() { $this->context = new RenderContext(); }
        };
    }

    public function test_get_last_loop_returns_stdclass(): void
    {
        $l = $this->loops();
        $l->addLoop([1, 2, 3]);
        $loop = $l->getLastLoop();
        $this->assertInstanceOf(\stdClass::class, $loop);
    }

    public function test_same_object_across_iterations(): void
    {
        $l = $this->loops();
        $l->addLoop([1, 2, 3]);

        $first = $l->getLastLoop();
        $l->incrementLoopIndices();
        $second = $l->getLastLoop();
        $l->incrementLoopIndices();
        $third = $l->getLastLoop();

        $this->assertSame($first, $second, 'Loop object must be reused across iterations.');
        $this->assertSame($first, $third);
    }

    public function test_iteration_counters_update_in_place(): void
    {
        $l = $this->loops();
        $l->addLoop(['a', 'b', 'c']);
        $loop = $l->getLastLoop();

        $this->assertSame(0, $loop->iteration);
        $this->assertSame(3, $loop->count);
        $this->assertTrue($loop->even);
        $this->assertFalse($loop->odd);

        $l->incrementLoopIndices();
        $this->assertSame(1, $loop->iteration);
        $this->assertSame(0, $loop->index);
        $this->assertTrue($loop->first);
        $this->assertFalse($loop->last);
        $this->assertSame(2, $loop->remaining);
        $this->assertTrue($loop->odd);
        $this->assertFalse($loop->even);

        $l->incrementLoopIndices();
        $this->assertSame(2, $loop->iteration);
        $this->assertFalse($loop->first);
        $this->assertFalse($loop->last);
        $this->assertSame(1, $loop->remaining);

        $l->incrementLoopIndices();
        $this->assertSame(3, $loop->iteration);
        $this->assertTrue($loop->last);
        $this->assertSame(0, $loop->remaining);
    }

    public function test_nested_loops_have_parent(): void
    {
        $l = $this->loops();
        $l->addLoop([1, 2]);
        $outer = $l->getLastLoop();
        $l->addLoop(['a', 'b']);
        $inner = $l->getLastLoop();

        $this->assertNotSame($outer, $inner);
        $this->assertSame($outer, $inner->parent);
        $this->assertSame(1, $outer->depth);
        $this->assertSame(2, $inner->depth);

        $l->popLoop();
        $this->assertSame($outer, $l->getLastLoop());
    }

    public function test_uncountable_data_leaves_count_null(): void
    {
        $l = $this->loops();
        // Pass a generator — not Countable.
        $gen = (function () { yield 1; yield 2; })();
        $l->addLoop($gen);
        $loop = $l->getLastLoop();

        $this->assertNull($loop->count);
        $this->assertNull($loop->remaining);
        // last is initially null for unknown lengths — only set by increment
        // when count is known.
        $this->assertFalse((bool) $loop->last);
    }
}
