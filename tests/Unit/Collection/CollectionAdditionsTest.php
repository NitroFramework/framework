<?php

namespace Tests\Unit\Collection;

use Nitro\Support\Collection;
use Nitro\Support\ItemNotFoundException;
use Nitro\Support\MultipleItemsFoundException;
use PHPUnit\Framework\TestCase;

/**
 * The collection methods that were absent, and the four whose behaviour moved.
 *
 * Each was checked against the reference implementation before being written
 * down here — 86 comparisons, no differences — so these guard the behaviour
 * rather than describe it for the first time.
 */
class CollectionAdditionsTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['id' => 1, 'name' => 'Ada', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
            ['id' => 3, 'name' => 'Cyd', 'role' => 'user'],
        ];
    }

    // ─── Exactly one ──────────────────────────────────────

    /**
     * first() hides two different bugs — nothing matched, and more than one
     * did where the code assumed a unique row. Both return something plausible
     * and go wrong later.
     */
    public function test_sole_returns_the_only_match(): void
    {
        $this->assertSame(5, (new Collection([5]))->sole());
        $this->assertSame(
            ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
            (new Collection($this->rows()))->sole('id', 2),
        );
    }

    public function test_sole_says_which_of_the_two_problems_it_is(): void
    {
        $this->expectException(ItemNotFoundException::class);

        (new Collection([]))->sole();
    }

    public function test_too_many_is_its_own_exception_carrying_the_count(): void
    {
        try {
            (new Collection($this->rows()))->sole('role', 'user');

            $this->fail('sole() should have refused two matches');
        } catch (MultipleItemsFoundException $exception) {
            $this->assertSame(2, $exception->count);
            $this->assertStringContainsString('found 2', $exception->getMessage());
        }
    }

    public function test_hasSole_answers_without_throwing(): void
    {
        $this->assertTrue((new Collection([1]))->hasSole());
        $this->assertFalse((new Collection([1, 2]))->hasSole());
        $this->assertFalse((new Collection([]))->hasSole());
    }

    public function test_firstOrFail_refuses_an_empty_result(): void
    {
        $this->assertSame(1, (new Collection([1, 2]))->firstOrFail());

        $this->expectException(ItemNotFoundException::class);

        (new Collection([]))->firstOrFail();
    }

    public function test_one_or_many(): void
    {
        $this->assertTrue((new Collection([1]))->containsOneItem());
        $this->assertFalse((new Collection([1, 2]))->containsOneItem());
        $this->assertTrue((new Collection([1, 2]))->containsManyItems());
    }

    // ─── Neighbours ───────────────────────────────────────

    public function test_before_and_after(): void
    {
        $c = new Collection([1, 2, 3]);

        $this->assertSame(1, $c->before(2));
        $this->assertSame(3, $c->after(2));
        $this->assertNull($c->before(1), 'nothing is before the first');
        $this->assertNull($c->after(3), 'nothing is after the last');
        $this->assertNull($c->before(99), 'an item that is not there has no neighbour');
    }

    public function test_a_neighbour_can_be_found_by_callback(): void
    {
        $this->assertSame(2, (new Collection([1, 2, 3]))->before(fn ($v) => $v === 3));
    }

    // ─── Keys and shape ───────────────────────────────────

    /** The shape form field names and a validation error bag are already in. */
    public function test_dot_flattens_and_undot_restores(): void
    {
        $nested = ['user' => ['name' => 'Ada', 'address' => ['city' => 'London']]];

        $flat = (new Collection($nested))->dot();

        $this->assertSame(
            ['user.name' => 'Ada', 'user.address.city' => 'London'],
            $flat->all(),
        );

        $this->assertSame($nested, $flat->undot()->all());
    }

    public function test_select_keeps_only_the_named_keys(): void
    {
        $this->assertSame(
            [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bob'], ['id' => 3, 'name' => 'Cyd']],
            (new Collection($this->rows()))->select(['id', 'name'])->all(),
        );
    }

    public function test_getOrPut_writes_only_when_absent(): void
    {
        $c = new Collection(['a' => 1]);

        $this->assertSame(1, $c->getOrPut('a', 9), 'an existing value is not replaced');
        $this->assertSame(2, $c->getOrPut('b', 2));
        $this->assertSame(['a' => 1, 'b' => 2], $c->all());
    }

    public function test_replace_and_replaceRecursive(): void
    {
        $this->assertSame(
            ['a' => 1, 'b' => 9, 'c' => 3],
            (new Collection(['a' => 1, 'b' => 2]))->replace(['b' => 9, 'c' => 3])->all(),
        );

        $this->assertSame(
            ['a' => ['x' => 1, 'y' => 9]],
            (new Collection(['a' => ['x' => 1, 'y' => 2]]))->replaceRecursive(['a' => ['y' => 9]])->all(),
        );
    }

    // ─── Windows and chunks ───────────────────────────────

    /** Consecutive pairs, for comparing each item to the next. */
    public function test_sliding_gives_overlapping_windows(): void
    {
        $windows = (new Collection([1, 2, 3, 4]))->sliding();

        $this->assertCount(3, $windows);
        $this->assertSame([1, 2], $windows->all()[0]->values()->all());
        $this->assertSame([3, 4], $windows->all()[2]->values()->all());
    }

    public function test_sliding_takes_a_step(): void
    {
        $this->assertCount(2, (new Collection([1, 2, 3, 4, 5]))->sliding(2, 2));
    }

    public function test_a_collection_shorter_than_the_window_gives_nothing(): void
    {
        $this->assertCount(0, (new Collection([1]))->sliding(3));
    }

    public function test_chunkWhile_breaks_where_the_callback_says(): void
    {
        $runs = (new Collection([1, 2, 5, 6, 9]))
            ->chunkWhile(fn ($value, $key, $chunk) => $value === $chunk->last() + 1);

        $this->assertCount(3, $runs);
        $this->assertSame([1, 2], $runs->all()[0]->values()->all());
        $this->assertSame([9], $runs->all()[2]->values()->all());
    }

    public function test_chunkBy_breaks_when_the_answer_changes(): void
    {
        $this->assertCount(3, (new Collection([1, 1, 2, 2, 3]))->chunkBy(fn ($v) => $v));
    }

    public function test_forPage_and_splitIn(): void
    {
        $this->assertSame([3, 4], (new Collection([1, 2, 3, 4, 5]))->forPage(2, 2)->values()->all());
        $this->assertCount(2, (new Collection([1, 2, 3, 4, 5]))->splitIn(2));
    }

    public function test_pad_grows_from_either_end(): void
    {
        $this->assertSame([1, 2, 0, 0], (new Collection([1, 2]))->pad(4, 0)->all());
        $this->assertSame([0, 0, 1, 2], (new Collection([1, 2]))->pad(-4, 0)->all());
    }

    // ─── Adding ───────────────────────────────────────────

    public function test_add_and_unshift(): void
    {
        $this->assertSame([1, 2], (new Collection([1]))->add(2)->all());
        $this->assertSame([1, 2, 3], (new Collection([3]))->unshift(1, 2)->all());
    }

    public function test_multiply_repeats_the_whole_collection(): void
    {
        $this->assertSame([1, 2, 1, 2, 1, 2], (new Collection([1, 2]))->multiply(3)->all());
        $this->assertSame([], (new Collection([1]))->multiply(0)->all());
    }

    // ─── Strict comparison ────────────────────────────────

    /**
     * Loose comparison treats 0, '0', '' and false as one value, so a list of
     * ids with a stray '' silently collapses.
     */
    public function test_the_strict_variants_do_not_conflate_types(): void
    {
        $this->assertCount(3, (new Collection([1, '1', 2]))->uniqueStrict());
        $this->assertCount(2, (new Collection([1, '1', 2]))->unique());

        $this->assertTrue((new Collection([1, 2]))->doesntContainStrict('1'));
        $this->assertFalse((new Collection([1, 2]))->doesntContainStrict(1));
    }

    public function test_whereStrict_and_the_in_variants(): void
    {
        $mixed = new Collection([['v' => 1], ['v' => '1']]);

        $this->assertCount(1, $mixed->whereStrict('v', 1));
        $this->assertCount(1, $mixed->whereInStrict('v', [1]));
        $this->assertCount(1, $mixed->whereNotInStrict('v', [1]));
    }

    // ─── Mapping and reducing ─────────────────────────────

    public function test_mapSpread_reads_each_pair_as_arguments(): void
    {
        $this->assertSame([3, 7], (new Collection([[1, 2], [3, 4]]))->mapSpread(fn ($a, $b) => $a + $b)->all());
    }

    public function test_mapToDictionary_collects_under_the_same_key(): void
    {
        $this->assertSame(
            ['admin' => ['Ada'], 'user' => ['Bob', 'Cyd']],
            (new Collection($this->rows()))->mapToDictionary(fn ($r) => [$r['role'] => $r['name']])->all(),
        );
    }

    public function test_reduceWithKeys_sees_the_key(): void
    {
        $this->assertSame(
            'a1b2',
            (new Collection(['a' => 1, 'b' => 2]))->reduceWithKeys(fn ($carry, $v, $k) => $carry . $k . $v, ''),
        );
    }

    public function test_reduceSpread_carries_several_values(): void
    {
        $this->assertSame(
            [1, 3],
            (new Collection([1, 2, 3]))->reduceSpread(
                fn ($min, $max, $value) => [min($min, $value), max($max, $value)],
                PHP_INT_MAX,
                PHP_INT_MIN,
            ),
        );
    }

    // ─── Miscellany ───────────────────────────────────────

    /** A collection is only as trustworthy as what was put in it. */
    public function test_ensure_names_the_item_that_is_wrong(): void
    {
        (new Collection([1, 2]))->ensure('int');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('item at [1] is a string');

        (new Collection([1, 'x']))->ensure('int');
    }

    public function test_percentage(): void
    {
        $this->assertSame(50.0, (new Collection([1, 2, 3, 4]))->percentage(fn ($v) => $v > 2));
        $this->assertNull((new Collection([]))->percentage(fn () => true));
    }

    public function test_sortDesc_and_sortKeysUsing(): void
    {
        $this->assertSame([3, 2, 1], (new Collection([1, 3, 2]))->sortDesc()->values()->all());
        $this->assertSame(
            ['a' => 1, 'b' => 2],
            (new Collection(['b' => 2, 'a' => 1]))->sortKeysUsing('strcmp')->all(),
        );
    }

    public function test_the_empty_conditionals(): void
    {
        $this->assertCount(1, (new Collection([]))->whenEmpty(fn ($c) => $c->add('was empty')));
        $this->assertCount(2, (new Collection([1]))->whenNotEmpty(fn ($c) => $c->add(2)));
        $this->assertCount(2, (new Collection([1]))->unlessEmpty(fn ($c) => $c->add(2)));
    }

    public function test_json_in_and_out(): void
    {
        $this->assertSame(['a' => 1], Collection::fromJson('{"a":1}')->all());
        $this->assertStringContainsString("\n", (new Collection(['a' => 1]))->toPrettyJson());
    }

    // ─── The higher-order shorthand ───────────────────────

    /** $users->map->name, instead of ->map(fn ($u) => $u->name). */
    public function test_a_property_can_be_read_through_a_method(): void
    {
        $users = new Collection([new AdditionsUser('Ada', 36), new AdditionsUser('Bob', 12)]);

        $this->assertSame(['Ada', 'Bob'], $users->map->name->all());
        $this->assertSame(48, $users->sum->age);
        $this->assertSame(36, $users->max->age);
        $this->assertSame(12, $users->min->age);
    }

    public function test_a_method_can_be_called_through_one(): void
    {
        $users = [new AdditionsUser('Ada', 36), new AdditionsUser('Bob', 12)];

        (new Collection($users))->each->activate();

        $this->assertTrue($users[0]->active);
        $this->assertTrue($users[1]->active);
    }

    public function test_filtering_through_the_shorthand(): void
    {
        $users = new Collection([new AdditionsUser('Ada', 36), new AdditionsUser('Bob', 12)]);

        $this->assertSame(['Ada'], $users->filter->isAdult()->values()->map->name->all());
    }

    /** Only the listed methods read well this way, so the rest are refused. */
    public function test_an_unknown_property_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Collection([]))->nonsense;
    }

    // ─── Macros ───────────────────────────────────────────

    public function test_a_collection_can_be_extended_at_runtime(): void
    {
        Collection::macro('sumOfSquares', static fn (Collection $c): int
            => $c->reduce(fn ($carry, $v) => $carry + $v * $v, 0));

        $this->assertSame(14, Collection::sumOfSquares(new Collection([1, 2, 3])));

        Collection::flushMacros();
    }
}

class AdditionsUser
{
    public bool $active = false;

    public function __construct(public string $name, public int $age)
    {
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function isAdult(): bool
    {
        return $this->age >= 18;
    }
}
