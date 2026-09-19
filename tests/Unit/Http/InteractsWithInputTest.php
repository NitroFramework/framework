<?php

namespace Tests\Unit\Http;

use Nitro\Http\Request;
use Nitro\Support\Carbon;
use Nitro\Support\Collection;
use Nitro\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * The difference between "arrived" and "usable".
 *
 * has() answers the first question; almost every controller wants the second.
 * `?name=` arrives, so has('name') is true for a field the user left blank —
 * which is why this surface exists rather than each call site writing
 * `input('x') !== null && input('x') !== ''` slightly differently.
 */
class InteractsWithInputTest extends TestCase
{
    private function request(array $query = [], array $body = []): Request
    {
        return new Request('GET', '/', [], $query, $body);
    }

    // ── present vs filled ────────────────────────────────────────────

    public function test_an_empty_field_is_present_but_not_filled(): void
    {
        $request = $this->request(['name' => '']);

        $this->assertTrue($request->has('name'));
        $this->assertFalse($request->filled('name'));
        $this->assertTrue($request->isNotFilled('name'));
    }

    public function test_whitespace_only_is_not_filled(): void
    {
        $this->assertFalse($this->request(['name' => "  \t "])->filled('name'));
    }

    public function test_zero_is_filled_because_somebody_typed_it(): void
    {
        $this->assertTrue($this->request(['qty' => '0'])->filled('qty'));
        $this->assertTrue($this->request(['qty' => 0])->filled('qty'));
    }

    public function test_an_empty_array_is_not_filled(): void
    {
        $this->assertFalse($this->request(['tags' => []])->filled('tags'));
        $this->assertTrue($this->request(['tags' => ['a']])->filled('tags'));
    }

    public function test_filled_requires_every_key_and_any_filled_requires_one(): void
    {
        $request = $this->request(['a' => 'x', 'b' => '']);

        $this->assertFalse($request->filled('a', 'b'));
        $this->assertTrue($request->anyFilled('a', 'b'));
        $this->assertFalse($request->anyFilled('b', 'missing'));
    }

    public function test_missing_and_has_any(): void
    {
        $request = $this->request(['a' => '']);

        $this->assertTrue($request->missing('nope'));
        $this->assertFalse($request->missing('a'));
        $this->assertTrue($request->hasAny('nope', 'a'));
        $this->assertFalse($request->hasAny('nope', 'neither'));
    }

    // ── conditionals ─────────────────────────────────────────────────

    public function test_when_filled_runs_only_for_a_usable_value(): void
    {
        $seen = [];

        $this->request(['a' => 'x', 'b' => ''])
            ->whenFilled('a', function ($value) use (&$seen) { $seen[] = "a:{$value}"; })
            ->whenFilled('b', function () use (&$seen) { $seen[] = 'b'; })
            ->whenFilled('c', function () use (&$seen) { $seen[] = 'c'; }, function () use (&$seen) { $seen[] = 'c:default'; });

        $this->assertSame(['a:x', 'c:default'], $seen);
    }

    public function test_when_has_fires_for_a_present_but_empty_value(): void
    {
        $seen = null;

        $this->request(['a' => ''])->whenHas('a', function ($value) use (&$seen) { $seen = $value; });

        $this->assertSame('', $seen, 'whenHas should fire where whenFilled would not');
    }

    public function test_when_missing_fires_only_when_absent(): void
    {
        $seen = [];

        $this->request(['a' => ''])
            ->whenMissing('a', function () use (&$seen) { $seen[] = 'a'; })
            ->whenMissing('b', function () use (&$seen) { $seen[] = 'b'; });

        $this->assertSame(['b'], $seen);
    }

    // ── casts ────────────────────────────────────────────────────────

    /**
     * An unchecked checkbox sends nothing at all, which is why the default has
     * to be false — a missing key is the common case, not an error.
     */
    public function test_boolean_reads_every_shape_a_checkbox_arrives_in(): void
    {
        foreach (['1', 'true', 'on', 'yes', 1, true] as $truthy) {
            $this->assertTrue($this->request(['v' => $truthy])->boolean('v'), var_export($truthy, true));
        }

        foreach (['0', 'false', 'off', 'no', 0, false, ''] as $falsy) {
            $this->assertFalse($this->request(['v' => $falsy])->boolean('v'), var_export($falsy, true));
        }

        $this->assertFalse($this->request()->boolean('absent'));
        $this->assertTrue($this->request()->boolean('absent', true));
    }

    public function test_numeric_casts_fall_back_rather_than_coercing_nonsense(): void
    {
        $request = $this->request(['n' => '42', 'f' => '1.5', 'junk' => 'abc']);

        $this->assertSame(42, $request->integer('n'));
        $this->assertSame(1.5, $request->float('f'));
        $this->assertSame(0, $request->integer('junk'));
        $this->assertSame(7, $request->integer('junk', 7));
        $this->assertSame(0, $request->integer('absent'));
    }

    public function test_string_refuses_to_flatten_an_array(): void
    {
        $this->assertSame('hi', $this->request(['s' => 'hi'])->string('s'));
        $this->assertSame('', $this->request(['s' => ['a', 'b']])->string('s'));
        $this->assertSame('x', $this->request()->string('absent', 'x'));
    }

    public function test_date_returns_the_frameworks_own_carbon_or_null(): void
    {
        $date = $this->request(['d' => '2026-09-19'])->date('d');

        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertSame('2026-09-19', $date->format('Y-m-d'));

        $this->assertSame('19/09/2026', $this->request(['d' => '19/09/2026'])->date('d', 'd/m/Y')?->format('d/m/Y'));
        $this->assertNull($this->request(['d' => 'not a date'])->date('d'));
        $this->assertNull($this->request()->date('absent'));
        $this->assertNull($this->request(['d' => ''])->date('d'));
    }

    public function test_enum_returns_a_case_or_null_never_an_invalid_one(): void
    {
        $this->assertSame(
            InputStatus::Active,
            $this->request(['s' => 'active'])->enum('s', InputStatus::class)
        );

        $this->assertNull($this->request(['s' => 'nope'])->enum('s', InputStatus::class));
        $this->assertNull($this->request()->enum('s', InputStatus::class));
    }

    public function test_collect_wraps_all_or_a_subset(): void
    {
        $request = $this->request(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertInstanceOf(Collection::class, $request->collect());
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $request->collect()->all());
        $this->assertSame(['a' => 1, 'c' => 3], $request->collect(['a', 'c'])->all());
        $this->assertSame(['b' => 2], $request->collect('b')->all());
    }

    // ── validate ─────────────────────────────────────────────────────

    public function test_validate_returns_only_the_validated_keys(): void
    {
        $request = $this->request(['name' => 'Ada', 'extra' => 'ignored']);

        $validated = $request->validate(['name' => 'required']);

        $this->assertSame(['name' => 'Ada'], $validated);
    }

    public function test_validate_throws_so_the_handler_can_redirect_back(): void
    {
        $this->expectException(ValidationException::class);

        $this->request(['name' => ''])->validate(['name' => 'required']);
    }
}

enum InputStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
