<?php

namespace Tests\Unit\Collection;

use PHPUnit\Framework\TestCase;
use Nitro\Support\Collection;

/**
 * =========================================================================
 * Collection Methods Tests — Untested Methods
 * =========================================================================
 */
class CollectionMethodsTest extends TestCase
{
    // =====================================================================
    // where()
    // =====================================================================

    public function test_where_with_two_args_defaults_to_equals(): void
    {
        $c = new Collection([
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Charlie', 'age' => 25],
        ]);

        $result = $c->where('age', 25);
        $this->assertCount(2, $result);
        $this->assertEquals('Alice', $result->all()[0]['name']);
        $this->assertEquals('Charlie', $result->all()[1]['name']);
    }

    public function test_where_with_three_args_operators(): void
    {
        $c = new Collection([
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Charlie', 'age' => 35],
        ]);

        $this->assertCount(2, $c->where('age', '>', 25));
        $this->assertCount(2, $c->where('age', '>=', 30));
        $this->assertCount(1, $c->where('age', '<', 30));
        $this->assertCount(2, $c->where('age', '<=', 30));
        $this->assertCount(2, $c->where('age', '!=', 25));
        $this->assertCount(2, $c->where('age', '<>', 25));
    }

    public function test_where_with_strict_equality(): void
    {
        $c = new Collection([
            ['val' => 1],
            ['val' => '1'],
            ['val' => true],
        ]);

        $result = $c->where('val', '===', 1);
        $this->assertCount(1, $result);
    }

    public function test_where_with_strict_inequality(): void
    {
        $c = new Collection([
            ['val' => 1],
            ['val' => '1'],
        ]);

        $result = $c->where('val', '!==', '1');
        $this->assertCount(1, $result);
        $this->assertSame(1, $result->first()['val']);
    }

    public function test_where_with_objects(): void
    {
        $c = new Collection([
            (object)['status' => 'active'],
            (object)['status' => 'inactive'],
            (object)['status' => 'active'],
        ]);

        $result = $c->where('status', 'active');
        $this->assertCount(2, $result);
    }

    // =====================================================================
    // whereIn()
    // =====================================================================

    public function test_whereIn_filters_by_array_of_values(): void
    {
        $c = new Collection([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
            ['id' => 4, 'name' => 'Dave'],
        ]);

        $result = $c->whereIn('id', [1, 3]);
        $this->assertCount(2, $result);
        $this->assertEquals('Alice', $result->all()[0]['name']);
        $this->assertEquals('Charlie', $result->all()[1]['name']);
    }

    public function test_whereIn_with_objects(): void
    {
        $c = new Collection([
            (object)['role' => 'admin'],
            (object)['role' => 'editor'],
            (object)['role' => 'viewer'],
        ]);

        $result = $c->whereIn('role', ['admin', 'viewer']);
        $this->assertCount(2, $result);
    }

    public function test_whereIn_returns_empty_when_no_match(): void
    {
        $c = new Collection([['id' => 1], ['id' => 2]]);
        $result = $c->whereIn('id', [99]);
        $this->assertCount(0, $result);
    }

    // =====================================================================
    // whereNotIn()
    // =====================================================================

    public function test_whereNotIn_excludes_values(): void
    {
        $c = new Collection([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
            ['id' => 4],
        ]);

        $result = $c->whereNotIn('id', [2, 4]);
        $this->assertCount(2, $result);
        $this->assertEquals(1, $result->all()[0]['id']);
        $this->assertEquals(3, $result->all()[1]['id']);
    }

    public function test_whereNotIn_with_objects(): void
    {
        $c = new Collection([
            (object)['type' => 'a'],
            (object)['type' => 'b'],
            (object)['type' => 'c'],
        ]);

        $result = $c->whereNotIn('type', ['b']);
        $this->assertCount(2, $result);
    }

    // =====================================================================
    // whereBetween()
    // =====================================================================

    public function test_whereBetween_inclusive_range(): void
    {
        $c = new Collection([
            ['score' => 10],
            ['score' => 50],
            ['score' => 75],
            ['score' => 100],
        ]);

        $result = $c->whereBetween('score', [50, 100]);
        $this->assertCount(3, $result);
    }

    public function test_whereBetween_boundary_values(): void
    {
        $c = new Collection([
            ['val' => 5],
            ['val' => 10],
            ['val' => 15],
        ]);

        $result = $c->whereBetween('val', [5, 15]);
        $this->assertCount(3, $result);
    }

    public function test_whereBetween_with_objects(): void
    {
        $c = new Collection([
            (object)['price' => 9.99],
            (object)['price' => 19.99],
            (object)['price' => 49.99],
        ]);

        $result = $c->whereBetween('price', [10, 50]);
        $this->assertCount(2, $result);
    }

    // =====================================================================
    // whereNotBetween()
    // =====================================================================

    public function test_whereNotBetween_excludes_range(): void
    {
        $c = new Collection([
            ['score' => 10],
            ['score' => 50],
            ['score' => 75],
            ['score' => 100],
        ]);

        $result = $c->whereNotBetween('score', [50, 75]);
        $this->assertCount(2, $result);
        $this->assertEquals(10, $result->all()[0]['score']);
        $this->assertEquals(100, $result->all()[1]['score']);
    }

    public function test_whereNotBetween_boundary_excluded(): void
    {
        $c = new Collection([
            ['val' => 5],
            ['val' => 10],
            ['val' => 15],
        ]);

        $result = $c->whereNotBetween('val', [5, 15]);
        $this->assertCount(0, $result);
    }

    // =====================================================================
    // whereNull()
    // =====================================================================

    public function test_whereNull_filters_null_values(): void
    {
        $c = new Collection([
            ['name' => 'Alice', 'email' => 'a@a.com'],
            ['name' => 'Bob', 'email' => null],
            ['name' => 'Charlie'],
        ]);

        $result = $c->whereNull('email');
        $this->assertCount(2, $result);
    }

    public function test_whereNull_without_key_filters_null_items(): void
    {
        $c = new Collection([1, null, 3, null, 5]);
        $result = $c->whereNull();
        $this->assertCount(2, $result);
    }

    public function test_whereNull_with_objects(): void
    {
        $c = new Collection([
            (object)['val' => 'x'],
            (object)['val' => null],
        ]);

        $result = $c->whereNull('val');
        $this->assertCount(1, $result);
    }

    // =====================================================================
    // whereNotNull()
    // =====================================================================

    public function test_whereNotNull_excludes_null_values(): void
    {
        $c = new Collection([
            ['name' => 'Alice', 'email' => 'a@a.com'],
            ['name' => 'Bob', 'email' => null],
            ['name' => 'Charlie'],
        ]);

        $result = $c->whereNotNull('email');
        $this->assertCount(1, $result);
    }

    public function test_whereNotNull_without_key(): void
    {
        $c = new Collection([1, null, 3, null, 5]);
        $result = $c->whereNotNull();
        $this->assertCount(3, $result);
    }

    // =====================================================================
    // groupBy()
    // =====================================================================

    public function test_groupBy_string_key(): void
    {
        $c = new Collection([
            ['dept' => 'sales', 'name' => 'Alice'],
            ['dept' => 'dev', 'name' => 'Bob'],
            ['dept' => 'sales', 'name' => 'Charlie'],
        ]);

        $groups = $c->groupBy('dept');
        $this->assertCount(2, $groups);
        $this->assertCount(2, $groups->all()['sales']);
        $this->assertCount(1, $groups->all()['dev']);
    }

    public function test_groupBy_callback(): void
    {
        $c = new Collection([1, 2, 3, 4, 5, 6]);
        $groups = $c->groupBy(fn($item) => $item % 2 === 0 ? 'even' : 'odd');

        $this->assertCount(2, $groups);
        $this->assertCount(3, $groups->all()['even']);
        $this->assertCount(3, $groups->all()['odd']);
    }

    public function test_groupBy_returns_collections_as_groups(): void
    {
        $c = new Collection([
            ['type' => 'a', 'val' => 1],
            ['type' => 'a', 'val' => 2],
        ]);

        $groups = $c->groupBy('type');
        $this->assertInstanceOf(Collection::class, $groups->all()['a']);
    }

    // =====================================================================
    // keyBy()
    // =====================================================================

    public function test_keyBy_string_key(): void
    {
        $c = new Collection([
            ['id' => 10, 'name' => 'Alice'],
            ['id' => 20, 'name' => 'Bob'],
        ]);

        $keyed = $c->keyBy('id');
        $this->assertArrayHasKey(10, $keyed->all());
        $this->assertArrayHasKey(20, $keyed->all());
        $this->assertEquals('Alice', $keyed->all()[10]['name']);
    }

    public function test_keyBy_callback(): void
    {
        $c = new Collection([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $keyed = $c->keyBy(fn($item) => strtoupper($item['name']));
        $this->assertArrayHasKey('ALICE', $keyed->all());
        $this->assertArrayHasKey('BOB', $keyed->all());
    }

    // =====================================================================
    // chunk()
    // =====================================================================

    public function test_chunk_splits_into_sized_groups(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $chunks = $c->chunk(2);

        $this->assertCount(3, $chunks);
        $this->assertCount(2, $chunks->all()[0]);
        $this->assertCount(2, $chunks->all()[1]);
        $this->assertCount(1, $chunks->all()[2]);
    }

    public function test_chunk_returns_collections(): void
    {
        $c = new Collection([1, 2, 3]);
        $chunks = $c->chunk(2);
        $this->assertInstanceOf(Collection::class, $chunks->all()[0]);
    }

    public function test_chunk_with_zero_or_negative_returns_empty(): void
    {
        $c = new Collection([1, 2, 3]);
        $this->assertCount(0, $c->chunk(0));
        $this->assertCount(0, $c->chunk(-1));
    }

    // =====================================================================
    // split()
    // =====================================================================

    public function test_split_into_groups(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $groups = $c->split(3);

        $this->assertCount(3, $groups);
        $this->assertInstanceOf(Collection::class, $groups->all()[0]);
    }

    public function test_split_empty_collection(): void
    {
        $c = new Collection();
        $this->assertCount(0, $c->split(3));
    }

    public function test_split_even_distribution(): void
    {
        $c = new Collection([1, 2, 3, 4]);
        $groups = $c->split(2);
        $this->assertCount(2, $groups);
        $this->assertCount(2, $groups->all()[0]);
        $this->assertCount(2, $groups->all()[1]);
    }

    // =====================================================================
    // flatten()
    // =====================================================================

    public function test_flatten_nested_arrays(): void
    {
        $c = new Collection([[1, 2], [3, 4], [5]]);
        $flat = $c->flatten(PHP_INT_MAX);
        $this->assertEquals([1, 2, 3, 4, 5], $flat->all());
    }

    public function test_flatten_deeply_nested(): void
    {
        $c = new Collection([[1, [2, [3]]], [4]]);
        $flat = $c->flatten(PHP_INT_MAX);
        $this->assertEquals([1, 2, 3, 4], $flat->all());
    }

    public function test_flatten_with_depth_limit(): void
    {
        $c = new Collection([[1, [2, [3]]], [4]]);
        $flat = $c->flatten(1);
        $this->assertEquals([1, [2, [3]], 4], $flat->all());
    }

    public function test_flatten_with_nested_collections(): void
    {
        $c = new Collection([new Collection([1, 2]), new Collection([3])]);
        $flat = $c->flatten(PHP_INT_MAX);
        $this->assertEquals([1, 2, 3], $flat->all());
    }

    public function test_flatten_non_array_items_untouched(): void
    {
        $c = new Collection([1, 'two', 3.0]);
        $this->assertEquals([1, 'two', 3.0], $c->flatten(PHP_INT_MAX)->all());
    }

    // =====================================================================
    // collapse()
    // =====================================================================

    public function test_collapse_arrays(): void
    {
        $c = new Collection([[1, 2], [3, 4], [5]]);
        $this->assertEquals([1, 2, 3, 4, 5], $c->collapse()->all());
    }

    public function test_collapse_with_collections(): void
    {
        $c = new Collection([new Collection([1, 2]), new Collection([3])]);
        $this->assertEquals([1, 2, 3], $c->collapse()->all());
    }

    public function test_collapse_skips_non_array_items(): void
    {
        $c = new Collection([[1, 2], 'skip', [3]]);
        $this->assertEquals([1, 2, 3], $c->collapse()->all());
    }

    // =====================================================================
    // merge()
    // =====================================================================

    public function test_merge_arrays(): void
    {
        $c = new Collection([1, 2]);
        $merged = $c->merge([3, 4]);
        $this->assertEquals([1, 2, 3, 4], $merged->all());
    }

    public function test_merge_with_collection(): void
    {
        $c = new Collection([1, 2]);
        $merged = $c->merge(new Collection([3, 4]));
        $this->assertEquals([1, 2, 3, 4], $merged->all());
    }

    public function test_merge_string_keys_overwrite(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2]);
        $merged = $c->merge(['b' => 99, 'c' => 3]);
        $this->assertEquals(['a' => 1, 'b' => 99, 'c' => 3], $merged->all());
    }

    // =====================================================================
    // diff()
    // =====================================================================

    public function test_diff_returns_items_not_in_other(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $diff = $c->diff([2, 4]);
        $this->assertEquals([1, 3, 5], $diff->all());
    }

    public function test_diff_with_collection(): void
    {
        $c = new Collection(['a', 'b', 'c']);
        $diff = $c->diff(new Collection(['b']));
        $this->assertEquals(['a', 'c'], $diff->all());
    }

    // =====================================================================
    // intersect()
    // =====================================================================

    public function test_intersect_returns_common_items(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $result = $c->intersect([2, 4, 6]);
        $this->assertEquals([2, 4], $result->all());
    }

    public function test_intersect_with_collection(): void
    {
        $c = new Collection(['a', 'b', 'c']);
        $result = $c->intersect(new Collection(['b', 'c', 'd']));
        $this->assertEquals(['b', 'c'], $result->all());
    }

    // =====================================================================
    // reduce()
    // =====================================================================

    public function test_reduce_sums_values(): void
    {
        $c = new Collection([1, 2, 3, 4]);
        $sum = $c->reduce(fn($carry, $item) => $carry + $item, 0);
        $this->assertEquals(10, $sum);
    }

    public function test_reduce_concatenates_strings(): void
    {
        $c = new Collection(['a', 'b', 'c']);
        $result = $c->reduce(fn($carry, $item) => $carry . $item, '');
        $this->assertEquals('abc', $result);
    }

    public function test_reduce_with_null_initial(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->reduce(fn($carry, $item) => ($carry ?? 0) + $item);
        $this->assertEquals(6, $result);
    }

    // =====================================================================
    // every()
    // =====================================================================

    public function test_every_returns_true_when_all_pass(): void
    {
        $c = new Collection([2, 4, 6, 8]);
        $this->assertTrue($c->every(fn($item) => $item % 2 === 0));
    }

    public function test_every_returns_false_when_one_fails(): void
    {
        $c = new Collection([2, 4, 5, 8]);
        $this->assertFalse($c->every(fn($item) => $item % 2 === 0));
    }

    public function test_every_on_empty_returns_true(): void
    {
        $c = new Collection();
        $this->assertTrue($c->every(fn($item) => false));
    }

    // =====================================================================
    // partition()
    // =====================================================================

    public function test_partition_splits_by_callback(): void
    {
        $c = new Collection([1, 2, 3, 4, 5, 6]);
        $parts = $c->partition(fn($item) => $item % 2 === 0);

        $this->assertCount(2, $parts);
        $this->assertEquals([2, 4, 6], $parts->all()[0]->all());
        $this->assertEquals([1, 3, 5], $parts->all()[1]->all());
    }

    public function test_partition_returns_collections(): void
    {
        $c = new Collection([1, 2]);
        $parts = $c->partition(fn($item) => $item > 1);

        $this->assertInstanceOf(Collection::class, $parts->all()[0]);
        $this->assertInstanceOf(Collection::class, $parts->all()[1]);
    }

    // =====================================================================
    // sortBy()
    // =====================================================================

    public function test_sortBy_string_key(): void
    {
        $c = new Collection([
            ['name' => 'Charlie'],
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        $sorted = $c->sortBy('name');
        $names = $sorted->pluck('name')->all();
        $this->assertEquals(['Alice', 'Bob', 'Charlie'], $names);
    }

    public function test_sortBy_callback(): void
    {
        $c = new Collection([
            ['name' => 'Charlie', 'age' => 20],
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ]);

        $sorted = $c->sortBy(fn($item) => $item['age']);
        $this->assertEquals(20, $sorted->first()['age']);
    }

    public function test_sortBy_with_objects(): void
    {
        $c = new Collection([
            (object)['score' => 90],
            (object)['score' => 70],
            (object)['score' => 80],
        ]);

        $sorted = $c->sortBy('score');
        $this->assertEquals(70, $sorted->first()->score);
    }

    // =====================================================================
    // sortByDesc()
    // =====================================================================

    public function test_sortByDesc_reverses_order(): void
    {
        $c = new Collection([
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Charlie', 'age' => 20],
        ]);

        $sorted = $c->sortByDesc('age');
        $this->assertEquals(30, $sorted->first()['age']);
        $this->assertEquals(20, $sorted->last()['age']);
    }

    // =====================================================================
    // sortKeys()
    // =====================================================================

    public function test_sortKeys_ascending(): void
    {
        $c = new Collection(['c' => 3, 'a' => 1, 'b' => 2]);
        $sorted = $c->sortKeys();
        $this->assertEquals(['a', 'b', 'c'], array_keys($sorted->all()));
    }

    public function test_sortKeys_descending(): void
    {
        $c = new Collection(['a' => 1, 'c' => 3, 'b' => 2]);
        $sorted = $c->sortKeys(SORT_REGULAR, true);
        $this->assertEquals(['c', 'b', 'a'], array_keys($sorted->all()));
    }

    // =====================================================================
    // median()
    // =====================================================================

    public function test_median_odd_count(): void
    {
        $c = new Collection([1, 3, 5]);
        $this->assertEquals(3, $c->median());
    }

    public function test_median_even_count(): void
    {
        $c = new Collection([1, 2, 3, 4]);
        $this->assertEquals(2.5, $c->median());
    }

    public function test_median_with_key(): void
    {
        $c = new Collection([
            ['score' => 10],
            ['score' => 20],
            ['score' => 30],
        ]);
        $this->assertEquals(20, $c->median('score'));
    }

    public function test_median_empty_returns_null(): void
    {
        $c = new Collection();
        $this->assertNull($c->median());
    }

    public function test_median_single_item(): void
    {
        $c = new Collection([42]);
        $this->assertEquals(42, $c->median());
    }

    // =====================================================================
    // avg()
    // =====================================================================

    public function test_avg_simple(): void
    {
        $c = new Collection([10, 20, 30]);
        $this->assertEquals(20, $c->avg());
    }

    public function test_avg_with_key(): void
    {
        $c = new Collection([
            ['score' => 80],
            ['score' => 90],
            ['score' => 100],
        ]);
        $this->assertEquals(90, $c->avg('score'));
    }

    public function test_avg_empty_returns_null(): void
    {
        $c = new Collection();
        $this->assertNull($c->avg());
    }

    // =====================================================================
    // min()
    // =====================================================================

    public function test_min_simple(): void
    {
        $c = new Collection([5, 3, 8, 1, 9]);
        $this->assertEquals(1, $c->min());
    }

    public function test_min_with_key(): void
    {
        $c = new Collection([
            ['price' => 50],
            ['price' => 10],
            ['price' => 30],
        ]);
        $this->assertEquals(10, $c->min('price'));
    }

    // =====================================================================
    // max()
    // =====================================================================

    public function test_max_simple(): void
    {
        $c = new Collection([5, 3, 8, 1, 9]);
        $this->assertEquals(9, $c->max());
    }

    public function test_max_with_key(): void
    {
        $c = new Collection([
            ['price' => 50],
            ['price' => 10],
            ['price' => 30],
        ]);
        $this->assertEquals(50, $c->max('price'));
    }

    // =====================================================================
    // mode()
    // =====================================================================

    public function test_mode_returns_most_frequent(): void
    {
        $c = new Collection([1, 2, 2, 3, 3, 3]);
        $this->assertEquals([3], $c->mode());
    }

    public function test_mode_multiple_modes(): void
    {
        $c = new Collection([1, 1, 2, 2, 3]);
        $modes = $c->mode();
        $this->assertContains(1, $modes);
        $this->assertContains(2, $modes);
        $this->assertCount(2, $modes);
    }

    public function test_mode_with_key(): void
    {
        $c = new Collection([
            ['color' => 'red'],
            ['color' => 'blue'],
            ['color' => 'red'],
        ]);
        $this->assertEquals(['red'], $c->mode('color'));
    }

    public function test_mode_empty_returns_null(): void
    {
        $c = new Collection();
        $this->assertNull($c->mode());
    }

    // =====================================================================
    // tap()
    // =====================================================================

    public function test_tap_calls_callback_and_returns_collection(): void
    {
        $tapped = null;
        $c = new Collection([1, 2, 3]);
        $result = $c->tap(function ($collection) use (&$tapped) {
            $tapped = $collection->all();
        });

        $this->assertEquals([1, 2, 3], $tapped);
        $this->assertSame($c, $result);
    }

    // =====================================================================
    // pipe()
    // =====================================================================

    public function test_pipe_passes_collection_to_callback(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->pipe(fn($collection) => $collection->sum());
        $this->assertEquals(6, $result);
    }

    public function test_pipe_can_return_non_collection(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->pipe(fn($col) => 'count: ' . $col->count());
        $this->assertEquals('count: 3', $result);
    }

    // =====================================================================
    // when()
    // =====================================================================

    public function test_when_true_executes_callback(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->when(true, fn($col) => $col->merge([4]));
        $this->assertEquals([1, 2, 3, 4], $result->all());
    }

    public function test_when_false_skips_callback(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->when(false, fn($col) => $col->merge([4]));
        $this->assertEquals([1, 2, 3], $result->all());
    }

    public function test_when_false_runs_default(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->when(
            false,
            fn($col) => $col->merge([4]),
            fn($col) => $col->merge([99])
        );
        $this->assertEquals([1, 2, 3, 99], $result->all());
    }

    public function test_when_callable_condition(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->when(
            fn($col) => $col->count() > 2,
            fn($col) => $col->merge([4])
        );
        $this->assertEquals([1, 2, 3, 4], $result->all());
    }

    // =====================================================================
    // unless()
    // =====================================================================

    public function test_unless_false_executes_callback(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->unless(false, fn($col) => $col->merge([4]));
        $this->assertEquals([1, 2, 3, 4], $result->all());
    }

    public function test_unless_true_skips_callback(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->unless(true, fn($col) => $col->merge([4]));
        $this->assertEquals([1, 2, 3], $result->all());
    }

    public function test_unless_true_runs_default(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->unless(
            true,
            fn($col) => $col->merge([4]),
            fn($col) => $col->merge([99])
        );
        $this->assertEquals([1, 2, 3, 99], $result->all());
    }

    // =====================================================================
    // has()
    // =====================================================================

    public function test_has_single_key(): void
    {
        $c = new Collection(['name' => 'Alice', 'age' => 25]);
        $this->assertTrue($c->has('name'));
        $this->assertFalse($c->has('email'));
    }

    public function test_has_multiple_keys(): void
    {
        $c = new Collection(['name' => 'Alice', 'age' => 25, 'city' => 'LA']);
        $this->assertTrue($c->has(['name', 'age']));
        $this->assertFalse($c->has(['name', 'email']));
    }

    public function test_has_numeric_keys(): void
    {
        $c = new Collection([10, 20, 30]);
        $this->assertTrue($c->has(0));
        $this->assertTrue($c->has(2));
        $this->assertFalse($c->has(3));
    }

    // =====================================================================
    // hasAny()
    // =====================================================================

    public function test_hasAny_returns_true_if_any_key_exists(): void
    {
        $c = new Collection(['name' => 'Alice', 'age' => 25]);
        $this->assertTrue($c->hasAny(['name', 'email']));
        $this->assertTrue($c->hasAny('age'));
    }

    public function test_hasAny_returns_false_if_none_exist(): void
    {
        $c = new Collection(['name' => 'Alice']);
        $this->assertFalse($c->hasAny(['email', 'phone']));
    }

    // =====================================================================
    // only()
    // =====================================================================

    public function test_only_returns_specified_keys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
        $result = $c->only(['a', 'c']);
        $this->assertEquals(['a' => 1, 'c' => 3], $result->all());
    }

    public function test_only_ignores_missing_keys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2]);
        $result = $c->only(['a', 'z']);
        $this->assertEquals(['a' => 1], $result->all());
    }

    // =====================================================================
    // except()
    // =====================================================================

    public function test_except_removes_specified_keys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
        $result = $c->except(['b', 'd']);
        $this->assertEquals(['a' => 1, 'c' => 3], $result->all());
    }

    public function test_except_ignores_missing_keys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2]);
        $result = $c->except(['z']);
        $this->assertEquals(['a' => 1, 'b' => 2], $result->all());
    }

    // =====================================================================
    // duplicates()
    // =====================================================================

    public function test_duplicates_finds_duplicate_values(): void
    {
        $c = new Collection([1, 2, 2, 3, 3, 3]);
        $dupes = $c->duplicates();
        $this->assertCount(3, $dupes); // index 2 => 2, index 4 => 3, index 5 => 3
    }

    public function test_duplicates_with_key(): void
    {
        $c = new Collection([
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Alice'],
        ]);

        $dupes = $c->duplicates('name');
        $this->assertCount(1, $dupes);
        $this->assertEquals('Alice', $dupes->all()[2]);
    }

    public function test_duplicates_no_duplicates(): void
    {
        $c = new Collection([1, 2, 3]);
        $this->assertCount(0, $c->duplicates());
    }

    // =====================================================================
    // pop()
    // =====================================================================

    public function test_pop_removes_and_returns_last(): void
    {
        $c = new Collection([1, 2, 3]);
        $popped = $c->pop();
        $this->assertEquals(3, $popped);
        $this->assertEquals([1, 2], $c->all());
    }

    public function test_pop_multiple(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $popped = $c->pop(2);
        $this->assertInstanceOf(Collection::class, $popped);
        $this->assertCount(2, $popped);
        $this->assertEquals([1, 2, 3], $c->all());
    }

    public function test_pop_from_empty(): void
    {
        $c = new Collection();
        $this->assertNull($c->pop());
    }

    public function test_pop_multiple_from_empty(): void
    {
        $c = new Collection();
        $result = $c->pop(3);
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    // =====================================================================
    // shift()
    // =====================================================================

    public function test_shift_removes_and_returns_first(): void
    {
        $c = new Collection([1, 2, 3]);
        $shifted = $c->shift();
        $this->assertEquals(1, $shifted);
        $this->assertEquals([2, 3], $c->all());
    }

    public function test_shift_multiple(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $shifted = $c->shift(2);
        $this->assertInstanceOf(Collection::class, $shifted);
        $this->assertCount(2, $shifted);
        $this->assertEquals([1, 2], $shifted->all());
        $this->assertEquals([3, 4, 5], $c->all());
    }

    public function test_shift_from_empty(): void
    {
        $c = new Collection();
        $this->assertNull($c->shift());
    }

    // =====================================================================
    // forget()
    // =====================================================================

    public function test_forget_single_key(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
        $c->forget('b');
        $this->assertEquals(['a' => 1, 'c' => 3], $c->all());
    }

    public function test_forget_multiple_keys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
        $c->forget(['a', 'c']);
        $this->assertEquals(['b' => 2, 'd' => 4], $c->all());
    }

    public function test_forget_numeric_keys(): void
    {
        $c = new Collection([10, 20, 30]);
        $c->forget(1);
        $this->assertArrayNotHasKey(1, $c->all());
        $this->assertCount(2, $c);
    }

    // =====================================================================
    // splice()
    // =====================================================================

    public function test_splice_removes_from_offset(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $removed = $c->splice(2);
        $this->assertEquals([3, 4, 5], $removed->all());
        $this->assertEquals([1, 2], $c->all());
    }

    public function test_splice_with_length(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $removed = $c->splice(1, 2);
        $this->assertEquals([2, 3], $removed->all());
        $this->assertEquals([1, 4, 5], $c->all());
    }

    public function test_splice_with_replacement(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $removed = $c->splice(1, 2, [20, 30]);
        $this->assertEquals([2, 3], $removed->all());
        $this->assertEquals([1, 20, 30, 4, 5], $c->all());
    }

    // =====================================================================
    // prepend()
    // =====================================================================

    public function test_prepend_value(): void
    {
        $c = new Collection([2, 3, 4]);
        $c->prepend(1);
        $this->assertEquals([1, 2, 3, 4], $c->all());
    }

    public function test_prepend_with_key(): void
    {
        $c = new Collection(['b' => 2, 'c' => 3]);
        $c->prepend(1, 'a');
        $this->assertEquals(['a' => 1, 'b' => 2, 'c' => 3], $c->all());
    }

    // =====================================================================
    // put()
    // =====================================================================

    public function test_put_adds_or_updates_key(): void
    {
        $c = new Collection(['a' => 1]);
        $c->put('b', 2);
        $this->assertEquals(['a' => 1, 'b' => 2], $c->all());

        $c->put('a', 99);
        $this->assertEquals(['a' => 99, 'b' => 2], $c->all());
    }

    public function test_put_numeric_key(): void
    {
        $c = new Collection([10, 20]);
        $c->put(2, 30);
        $this->assertEquals([10, 20, 30], $c->all());
    }

    // =====================================================================
    // combine()
    // =====================================================================

    public function test_combine_keys_with_values(): void
    {
        $keys = new Collection(['name', 'age', 'city']);
        $result = $keys->combine(['Alice', 25, 'London']);
        $this->assertEquals(['name' => 'Alice', 'age' => 25, 'city' => 'London'], $result->all());
    }

    public function test_combine_with_collection(): void
    {
        $keys = new Collection(['a', 'b']);
        $result = $keys->combine(new Collection([1, 2]));
        $this->assertEquals(['a' => 1, 'b' => 2], $result->all());
    }

    // =====================================================================
    // union()
    // =====================================================================

    public function test_union_preserves_original_keys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2]);
        $result = $c->union(['b' => 99, 'c' => 3]);
        $this->assertEquals(['a' => 1, 'b' => 2, 'c' => 3], $result->all());
    }

    public function test_union_with_collection(): void
    {
        $c = new Collection(['x' => 10]);
        $result = $c->union(new Collection(['x' => 99, 'y' => 20]));
        $this->assertEquals(['x' => 10, 'y' => 20], $result->all());
    }

    // =====================================================================
    // concat()
    // =====================================================================

    public function test_concat_appends_values(): void
    {
        $c = new Collection([1, 2]);
        $result = $c->concat([3, 4]);
        $this->assertEquals([1, 2, 3, 4], $result->all());
    }

    public function test_concat_reindexes(): void
    {
        $c = new Collection(['a' => 1]);
        $result = $c->concat(['b' => 2]);
        // concat re-indexes everything with []= syntax
        $values = array_values($result->all());
        $this->assertEquals([1, 2], $values);
    }

    public function test_concat_with_collection(): void
    {
        $c = new Collection([1]);
        $result = $c->concat(new Collection([2, 3]));
        $this->assertEquals([1, 2, 3], $result->all());
    }

    // =====================================================================
    // zip()
    // =====================================================================

    public function test_zip_merges_arrays(): void
    {
        $c = new Collection([1, 2, 3]);
        $zipped = $c->zip(['a', 'b', 'c']);

        $this->assertCount(3, $zipped);
        $this->assertEquals([1, 'a'], $zipped->all()[0]->all());
        $this->assertEquals([2, 'b'], $zipped->all()[1]->all());
        $this->assertEquals([3, 'c'], $zipped->all()[2]->all());
    }

    public function test_zip_with_collection(): void
    {
        $c = new Collection([1, 2]);
        $zipped = $c->zip(new Collection(['x', 'y']));
        $this->assertEquals([1, 'x'], $zipped->all()[0]->all());
    }

    // =====================================================================
    // crossJoin()
    // =====================================================================

    public function test_crossJoin_cartesian_product(): void
    {
        $c = new Collection([1, 2]);
        $result = $c->crossJoin(['a', 'b']);

        $this->assertCount(4, $result);
        $this->assertEquals([1, 'a'], $result->all()[0]);
        $this->assertEquals([1, 'b'], $result->all()[1]);
        $this->assertEquals([2, 'a'], $result->all()[2]);
        $this->assertEquals([2, 'b'], $result->all()[3]);
    }

    public function test_crossJoin_three_arrays(): void
    {
        $c = new Collection([1, 2]);
        $result = $c->crossJoin(['a'], ['x', 'y']);
        $this->assertCount(4, $result);
        $this->assertEquals([1, 'a', 'x'], $result->all()[0]);
    }

    // =====================================================================
    // nth()
    // =====================================================================

    public function test_nth_every_n_items(): void
    {
        $c = new Collection([1, 2, 3, 4, 5, 6]);
        $result = $c->nth(2);
        $this->assertEquals([1, 3, 5], $result->all());
    }

    public function test_nth_with_offset(): void
    {
        $c = new Collection([1, 2, 3, 4, 5, 6]);
        $result = $c->nth(2, 1);
        $this->assertEquals([2, 4, 6], $result->all());
    }

    public function test_nth_step_three(): void
    {
        $c = new Collection([1, 2, 3, 4, 5, 6, 7, 8, 9]);
        $result = $c->nth(3);
        $this->assertEquals([1, 4, 7], $result->all());
    }

    // =====================================================================
    // mapWithKeys()
    // =====================================================================

    public function test_mapWithKeys_transforms_keys(): void
    {
        $c = new Collection([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $result = $c->mapWithKeys(fn($item) => [$item['id'] => $item['name']]);
        $this->assertEquals([1 => 'Alice', 2 => 'Bob'], $result->all());
    }

    public function test_mapWithKeys_with_objects(): void
    {
        $c = new Collection([
            (object)['code' => 'US', 'label' => 'United States'],
            (object)['code' => 'UK', 'label' => 'United Kingdom'],
        ]);

        $result = $c->mapWithKeys(fn($item) => [$item->code => $item->label]);
        $this->assertEquals(['US' => 'United States', 'UK' => 'United Kingdom'], $result->all());
    }

    // =====================================================================
    // flatMap()
    // =====================================================================

    public function test_flatMap_maps_and_collapses(): void
    {
        $c = new Collection([
            ['languages' => ['PHP', 'JS']],
            ['languages' => ['Python', 'Ruby']],
        ]);

        $result = $c->flatMap(fn($item) => $item['languages']);
        $this->assertEquals(['PHP', 'JS', 'Python', 'Ruby'], $result->all());
    }

    public function test_flatMap_with_key_value_pairs(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->flatMap(fn($item) => [$item, $item * 10]);
        $this->assertEquals([1, 10, 2, 20, 3, 30], $result->all());
    }

    // =====================================================================
    // flip()
    // =====================================================================

    public function test_flip_swaps_keys_and_values(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
        $result = $c->flip();
        $this->assertEquals([1 => 'a', 2 => 'b', 3 => 'c'], $result->all());
    }

    // =====================================================================
    // values()
    // =====================================================================

    public function test_values_resets_keys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
        $result = $c->values();
        $this->assertEquals([1, 2, 3], $result->all());
    }

    public function test_values_after_filter(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        // filter preserves values but reindexes via array_values internally
        $filtered = $c->filter(fn($item) => $item > 3);
        $result = $filtered->values();
        $this->assertEquals([4, 5], $result->all());
    }

    // =====================================================================
    // keys()
    // =====================================================================

    public function test_keys_returns_keys(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertEquals(['a', 'b', 'c'], $c->keys()->all());
    }

    public function test_keys_numeric(): void
    {
        $c = new Collection([10, 20, 30]);
        $this->assertEquals([0, 1, 2], $c->keys()->all());
    }

    // =====================================================================
    // implode()
    // =====================================================================

    public function test_implode_simple(): void
    {
        $c = new Collection(['a', 'b', 'c']);
        $this->assertEquals('a, b, c', $c->implode(', '));
    }

    public function test_implode_with_key(): void
    {
        $c = new Collection([
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Charlie'],
        ]);

        $this->assertEquals('Alice, Bob, Charlie', $c->implode(', ', 'name'));
    }

    // =====================================================================
    // join()
    // =====================================================================

    public function test_join_with_final_glue(): void
    {
        $c = new Collection(['Alice', 'Bob', 'Charlie']);
        $this->assertEquals('Alice, Bob and Charlie', $c->join(', ', ' and '));
    }

    public function test_join_two_items_with_final_glue(): void
    {
        $c = new Collection(['Alice', 'Bob']);
        $this->assertEquals('Alice and Bob', $c->join(', ', ' and '));
    }

    public function test_join_single_item(): void
    {
        $c = new Collection(['Alice']);
        $this->assertEquals('Alice', $c->join(', ', ' and '));
    }

    public function test_join_without_final_glue(): void
    {
        $c = new Collection(['a', 'b', 'c']);
        $this->assertEquals('a, b, c', $c->join(', '));
    }

    // =====================================================================
    // search()
    // =====================================================================

    public function test_search_returns_key(): void
    {
        $c = new Collection([10, 20, 30]);
        $this->assertEquals(1, $c->search(20));
    }

    public function test_search_strict(): void
    {
        $c = new Collection([1, '1', 2]);
        $this->assertEquals(0, $c->search(1, true));
    }

    public function test_search_with_callback(): void
    {
        $c = new Collection([10, 20, 30, 40]);
        $key = $c->search(fn($item) => $item > 25);
        $this->assertEquals(2, $key);
    }

    public function test_search_not_found(): void
    {
        $c = new Collection([1, 2, 3]);
        $this->assertFalse($c->search(99));
    }

    // =====================================================================
    // find()
    // =====================================================================

    public function test_find_returns_default_when_no_getKey(): void
    {
        $c = new Collection([['id' => 1], ['id' => 2]]);
        $this->assertNull($c->find(1));
    }

    public function test_find_with_getKey_method(): void
    {
        $obj = new class(5) {
            public int $id;
            public function __construct(int $id)
            {
                $this->id = $id;
            }
            public function getKey(): int
            {
                return $this->id;
            }
        };

        $c = new Collection([$obj]);
        $this->assertSame($obj, $c->find(5));
        $this->assertNull($c->find(99));
    }

    public function test_find_default_value(): void
    {
        $c = new Collection([]);
        $this->assertEquals('fallback', $c->find(1, 'fallback'));
    }

    // =====================================================================
    // pull()
    // =====================================================================

    public function test_pull_removes_and_returns(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
        $pulled = $c->pull('b');
        $this->assertEquals(2, $pulled);
        $this->assertEquals(['a' => 1, 'c' => 3], $c->all());
    }

    public function test_pull_default_when_missing(): void
    {
        $c = new Collection(['a' => 1]);
        $this->assertEquals('default', $c->pull('z', 'default'));
    }

    // =====================================================================
    // countBy()
    // =====================================================================

    public function test_countBy_counts_values(): void
    {
        $c = new Collection(['apple', 'banana', 'apple', 'cherry', 'banana', 'apple']);
        $counts = $c->countBy();
        $this->assertEquals(3, $counts->all()['apple']);
        $this->assertEquals(2, $counts->all()['banana']);
        $this->assertEquals(1, $counts->all()['cherry']);
    }

    public function test_countBy_with_callback(): void
    {
        $c = new Collection(['alice@gmail.com', 'bob@yahoo.com', 'charlie@gmail.com']);
        $counts = $c->countBy(fn($email) => explode('@', $email)[1]);
        $this->assertEquals(2, $counts->all()['gmail.com']);
        $this->assertEquals(1, $counts->all()['yahoo.com']);
    }

    // =====================================================================
    // get()
    // =====================================================================

    public function test_get_returns_value_by_key(): void
    {
        $c = new Collection(['a' => 10, 'b' => 20]);
        $this->assertEquals(10, $c->get('a'));
        $this->assertEquals(20, $c->get('b'));
    }

    public function test_get_returns_default_when_missing(): void
    {
        $c = new Collection(['a' => 1]);
        $this->assertNull($c->get('z'));
        $this->assertEquals('fallback', $c->get('z', 'fallback'));
    }

    public function test_get_numeric_key(): void
    {
        $c = new Collection([10, 20, 30]);
        $this->assertEquals(20, $c->get(1));
    }

    // =====================================================================
    // value()
    // =====================================================================

    public function test_value_from_array_items(): void
    {
        $c = new Collection([
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
        ]);
        $this->assertEquals('Alice', $c->value('name'));
    }

    public function test_value_from_object_items(): void
    {
        $c = new Collection([
            (object)['name' => 'Alice'],
            (object)['name' => 'Bob'],
        ]);
        $this->assertEquals('Alice', $c->value('name'));
    }

    public function test_value_returns_default_when_empty(): void
    {
        $c = new Collection();
        $this->assertNull($c->value('name'));
        $this->assertEquals('default', $c->value('name', 'default'));
    }

    public function test_value_returns_default_for_missing_column(): void
    {
        $c = new Collection([['name' => 'Alice']]);
        $this->assertNull($c->value('email'));
        $this->assertEquals('n/a', $c->value('email', 'n/a'));
    }

    // =====================================================================
    // firstWhere()
    // =====================================================================

    public function test_firstWhere_two_args(): void
    {
        $c = new Collection([
            ['name' => 'Alice', 'active' => false],
            ['name' => 'Bob', 'active' => true],
            ['name' => 'Charlie', 'active' => true],
        ]);

        $result = $c->firstWhere('active', true);
        $this->assertEquals('Bob', $result['name']);
    }

    public function test_firstWhere_three_args(): void
    {
        $c = new Collection([
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Charlie', 'age' => 35],
        ]);

        $result = $c->firstWhere('age', '>=', 30);
        $this->assertEquals('Bob', $result['name']);
    }

    public function test_firstWhere_not_found(): void
    {
        $c = new Collection([['name' => 'Alice']]);
        $this->assertNull($c->firstWhere('name', 'Nobody'));
    }

    public function test_firstWhere_with_objects(): void
    {
        $c = new Collection([
            (object)['type' => 'basic'],
            (object)['type' => 'premium'],
        ]);

        $result = $c->firstWhere('type', 'premium');
        $this->assertEquals('premium', $result->type);
    }

    // =====================================================================
    // containsStrict()
    // =====================================================================

    public function test_containsStrict_type_sensitive(): void
    {
        $c = new Collection([1, 2, 3]);
        $this->assertTrue($c->containsStrict(1));
        $this->assertFalse($c->containsStrict('1'));
    }

    public function test_containsStrict_with_key_value(): void
    {
        $c = new Collection([
            ['id' => 1, 'active' => true],
            ['id' => 2, 'active' => false],
        ]);

        $this->assertTrue($c->containsStrict('active', true));
        $this->assertFalse($c->containsStrict('active', 1)); // strict: 1 !== true
    }

    // =====================================================================
    // doesntContain()
    // =====================================================================

    public function test_doesntContain_value(): void
    {
        $c = new Collection([1, 2, 3]);
        $this->assertTrue($c->doesntContain(4));
        $this->assertFalse($c->doesntContain(2));
    }

    public function test_doesntContain_with_callback(): void
    {
        $c = new Collection([1, 2, 3]);
        $this->assertTrue($c->doesntContain(fn($item) => $item > 10));
        $this->assertFalse($c->doesntContain(fn($item) => $item > 2));
    }

    // =====================================================================
    // reject()
    // =====================================================================

    public function test_reject_removes_matching(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $result = $c->reject(fn($item, $key) => $item % 2 === 0);
        $this->assertEquals([1, 3, 5], $result->all());
    }

    public function test_reject_opposite_of_filter(): void
    {
        $c = new Collection([10, 20, 30, 40]);
        $filtered = $c->filter(fn($item) => $item > 20);
        $rejected = $c->reject(fn($item, $key) => $item <= 20);
        $this->assertEquals($filtered->all(), $rejected->all());
    }

    // =====================================================================
    // takeUntil()
    // =====================================================================

    public function test_takeUntil_with_value(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $result = $c->takeUntil(3);
        $this->assertEquals([1, 2], $result->all());
    }

    public function test_takeUntil_with_callback(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $result = $c->takeUntil(fn($item) => $item >= 4);
        $this->assertEquals([1, 2, 3], $result->all());
    }

    public function test_takeUntil_never_triggered(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->takeUntil(99);
        $this->assertEquals([1, 2, 3], $result->all());
    }

    // =====================================================================
    // takeWhile()
    // =====================================================================

    public function test_takeWhile_with_callback(): void
    {
        $c = new Collection([1, 2, 3, 4, 1, 2]);
        $result = $c->takeWhile(fn($item) => $item < 4);
        $this->assertEquals([1, 2, 3], $result->all());
    }

    public function test_takeWhile_stops_at_first_failure(): void
    {
        $c = new Collection([2, 4, 6, 3, 8]);
        $result = $c->takeWhile(fn($item) => $item % 2 === 0);
        $this->assertEquals([2, 4, 6], $result->all());
    }

    // =====================================================================
    // skipUntil()
    // =====================================================================

    public function test_skipUntil_with_value(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $result = $c->skipUntil(3);
        $this->assertEquals([3, 4, 5], $result->all());
    }

    public function test_skipUntil_with_callback(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $result = $c->skipUntil(fn($item) => $item > 3);
        $this->assertEquals([4, 5], $result->all());
    }

    public function test_skipUntil_never_found(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->skipUntil(99);
        $this->assertCount(0, $result);
    }

    // =====================================================================
    // skipWhile()
    // =====================================================================

    public function test_skipWhile_with_callback(): void
    {
        $c = new Collection([1, 2, 3, 4, 1]);
        $result = $c->skipWhile(fn($item) => $item < 3);
        $this->assertEquals([3, 4, 1], $result->all());
    }

    public function test_skipWhile_nothing_skipped(): void
    {
        $c = new Collection([5, 4, 3]);
        $result = $c->skipWhile(fn($item) => $item < 3);
        $this->assertEquals([5, 4, 3], $result->all());
    }

    // =====================================================================
    // transform()
    // =====================================================================

    public function test_transform_modifies_in_place(): void
    {
        $c = new Collection([1, 2, 3]);
        $result = $c->transform(fn($item) => $item * 2);

        $this->assertSame($c, $result); // returns same instance
        $this->assertEquals([2, 4, 6], $c->all());
    }

    public function test_transform_with_key(): void
    {
        $c = new Collection(['a', 'b', 'c']);
        $c->transform(fn($item, $key) => $key . ':' . $item);
        $this->assertEquals(['0:a', '1:b', '2:c'], $c->all());
    }

    // =====================================================================
    // wrap()
    // =====================================================================

    public function test_wrap_array(): void
    {
        $result = Collection::wrap([1, 2, 3]);
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertEquals([1, 2, 3], $result->all());
    }

    public function test_wrap_single_value(): void
    {
        $result = Collection::wrap('hello');
        $this->assertEquals(['hello'], $result->all());
    }

    public function test_wrap_collection_returns_same(): void
    {
        $original = new Collection([1, 2]);
        $wrapped = Collection::wrap($original);
        $this->assertSame($original, $wrapped);
    }

    public function test_wrap_null(): void
    {
        $result = Collection::wrap(null);
        $this->assertEquals([null], $result->all());
    }

    // =====================================================================
    // unwrap()
    // =====================================================================

    public function test_unwrap_collection(): void
    {
        $c = new Collection([1, 2, 3]);
        $this->assertEquals([1, 2, 3], Collection::unwrap($c));
    }

    public function test_unwrap_array(): void
    {
        $this->assertEquals([1, 2], Collection::unwrap([1, 2]));
    }



    // =====================================================================
    // times()
    // =====================================================================

    public function test_times_generates_range(): void
    {
        $c = Collection::times(5);
        $this->assertEquals([1, 2, 3, 4, 5], $c->all());
    }

    public function test_times_with_callback(): void
    {
        $c = Collection::times(3, fn($n) => $n * 10);
        $this->assertEquals([10, 20, 30], $c->all());
    }

    public function test_times_zero_returns_empty(): void
    {
        $c = Collection::times(0);
        $this->assertCount(0, $c);
    }

    public function test_times_negative_returns_empty(): void
    {
        $c = Collection::times(-5);
        $this->assertCount(0, $c);
    }

    // =====================================================================
    // range()
    // =====================================================================

    public function test_range_ascending(): void
    {
        $c = Collection::range(1, 5);
        $this->assertEquals([1, 2, 3, 4, 5], $c->all());
    }

    public function test_range_descending(): void
    {
        $c = Collection::range(5, 1);
        $this->assertEquals([5, 4, 3, 2, 1], $c->all());
    }

    public function test_range_single(): void
    {
        $c = Collection::range(3, 3);
        $this->assertEquals([3], $c->all());
    }

    public function test_range_negative(): void
    {
        $c = Collection::range(-2, 2);
        $this->assertEquals([-2, -1, 0, 1, 2], $c->all());
    }

    // =====================================================================
    // make()
    // =====================================================================

    public function test_make_creates_collection(): void
    {
        $c = Collection::make([1, 2, 3]);
        $this->assertInstanceOf(Collection::class, $c);
        $this->assertEquals([1, 2, 3], $c->all());
    }

    public function test_make_empty(): void
    {
        $c = Collection::make();
        $this->assertCount(0, $c);
    }
}
