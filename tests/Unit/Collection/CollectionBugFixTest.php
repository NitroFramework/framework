<?php

namespace Tests\Unit\Collection;

use Nitro\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for Collection bugs found in the layer sweep vs Laravel.
 */
class CollectionBugFixTest extends TestCase
{
    public function test_first_returns_first_element_of_keyed_collection(): void
    {
        $this->assertSame(1, Collection::make(['a' => 1, 'b' => 2])->first());
    }

    public function test_first_on_reindexed_keys(): void
    {
        // e.g. groupBy/keyBy produce non-zero first keys.
        $c = Collection::make([5 => 'x', 9 => 'y']);
        $this->assertSame('x', $c->first());
    }

    public function test_first_empty_returns_default(): void
    {
        $this->assertSame('none', Collection::make([])->first(null, 'none'));
    }

    public function test_flatten_with_no_argument_does_not_fatal(): void
    {
        $c = Collection::make([1, [2, [3, [4]]]])->flatten();
        $this->assertSame([1, 2, 3, 4], $c->all());
    }

    public function test_flatten_respects_explicit_depth(): void
    {
        $c = Collection::make([1, [2, [3]]])->flatten(1);
        $this->assertSame([1, 2, [3]], $c->all());
    }

    public function test_sort_preserves_keys(): void
    {
        $sorted = Collection::make(['a' => 3, 'b' => 1, 'c' => 2])->sort();
        $this->assertSame(['b' => 1, 'c' => 2, 'a' => 3], $sorted->all());
    }

    public function test_sort_by_preserves_keys(): void
    {
        $sorted = Collection::make([
            'x' => ['n' => 3],
            'y' => ['n' => 1],
        ])->sortBy('n');
        $this->assertSame(['y', 'x'], array_keys($sorted->all()));
    }

    public function test_min_max_on_empty_return_null(): void
    {
        $this->assertNull(Collection::make([])->min());
        $this->assertNull(Collection::make([])->max());
    }

    public function test_median_keeps_zero_values(): void
    {
        // [0,0,4,6] median = (0+4)/2 = 2. A falsy filter would drop the zeros
        // and wrongly compute median([4,6]) = 5.
        $this->assertSame(2, Collection::make([0, 0, 4, 6])->median());
    }
}
