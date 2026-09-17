<?php

namespace Tests\Unit\Support;

use Nitro\Support\Arr;
use Nitro\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * The Str and Arr helper surface.
 */
class StrArrTest extends TestCase
{
    // ─── Str: slicing ─────────────────────────────────────

    public function test_after_and_before_last(): void
    {
        $this->assertSame('c', Str::afterLast('a/b/c', '/'));
        $this->assertSame('a/b', Str::beforeLast('a/b/c', '/'));
        $this->assertSame('a/b/c', Str::afterLast('a/b/c', ''));
        $this->assertSame('nope', Str::afterLast('nope', 'x'));
    }

    public function test_between_and_between_first(): void
    {
        $this->assertSame('b/c', Str::between('[a]b/c[d]', ']', '['));
        $this->assertSame('hello', Str::between('<p>hello</p>', '>', '<'));
        $this->assertSame('a', Str::betweenFirst('[a][b]', '[', ']'));
    }

    public function test_char_at(): void
    {
        $this->assertSame('b', Str::charAt('abc', 1));
        $this->assertSame('c', Str::charAt('abc', -1));
        $this->assertFalse(Str::charAt('abc', 9));
    }

    public function test_take(): void
    {
        $this->assertSame('abc', Str::take('abcdef', 3));
        $this->assertSame('def', Str::take('abcdef', -3));
    }

    public function test_chop_start_and_end(): void
    {
        $this->assertSame('example.com', Str::chopStart('https://example.com', 'https://'));
        $this->assertSame('example', Str::chopEnd('example.com', '.com'));
        $this->assertSame('example.com', Str::chopStart('example.com', ['http://', 'https://']));
    }

    // ─── Str: searching ───────────────────────────────────

    public function test_contains_with_ignore_case(): void
    {
        $this->assertTrue(Str::contains('Hello World', 'WORLD', true));
        $this->assertFalse(Str::contains('Hello World', 'WORLD'));
    }

    public function test_contains_all_and_doesnt_contain(): void
    {
        $this->assertTrue(Str::containsAll('one two three', ['one', 'three']));
        $this->assertFalse(Str::containsAll('one two', ['one', 'three']));

        $this->assertTrue(Str::doesntContain('one two', 'three'));
        $this->assertFalse(Str::doesntContain('one two', 'two'));
    }

    public function test_is_matches_wildcards(): void
    {
        $this->assertTrue(Str::is('foo.*', 'foo.bar'));
        $this->assertTrue(Str::is('foo.bar', 'foo.bar'));
        $this->assertFalse(Str::is('foo.*', 'baz.bar'));
        $this->assertTrue(Str::is(['a*', 'b*'], 'bcd'));
    }

    public function test_match_and_match_all(): void
    {
        $this->assertSame('bar', Str::match('/foo (\w+)/', 'foo bar'));
        $this->assertSame('', Str::match('/nope (\w+)/', 'foo bar'));
        $this->assertSame(['a', 'b'], Str::matchAll('/(\w)/', 'a b'));
    }

    public function test_position_and_substr_count(): void
    {
        $this->assertSame(4, Str::position('foo bar', 'bar'));
        $this->assertFalse(Str::position('foo', 'zzz'));
        $this->assertSame(2, Str::substrCount('a-b-c', '-'));
    }

    // ─── Str: predicates ──────────────────────────────────

    public function test_predicates(): void
    {
        $this->assertTrue(Str::isAscii('plain'));
        $this->assertFalse(Str::isAscii('Müller'));

        $this->assertTrue(Str::isJson('{"a":1}'));
        $this->assertFalse(Str::isJson('{a:1}'));
        $this->assertFalse(Str::isJson('   '));

        $this->assertTrue(Str::isUrl('https://example.com'));
        $this->assertFalse(Str::isUrl('not a url'));

        $this->assertTrue(Str::isUlid('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        $this->assertFalse(Str::isUlid('short'));
    }

    // ─── Str: case ────────────────────────────────────────

    public function test_case_helpers(): void
    {
        $this->assertSame('fooBar', Str::lcfirst('FooBar'));
        $this->assertSame('FooBar', Str::pascal('foo_bar'));
        $this->assertSame(['Foo', 'Bar'], Str::ucsplit('FooBar'));
        $this->assertSame('Foo Bar Baz', Str::headline('foo_bar-baz'));
        $this->assertSame('Foo Bar', Str::headline('fooBar'));
    }

    public function test_initials(): void
    {
        $this->assertSame('AB', Str::initials('Ada Byron'));
        $this->assertSame('A.B', Str::initials('Ada Byron', '.'));
    }

    public function test_plural_studly(): void
    {
        $this->assertSame('Users', Str::pluralStudly('user'));
        $this->assertSame('User', Str::pluralStudly('user', 1));
    }

    // ─── Str: rewriting ───────────────────────────────────

    public function test_replace_first_and_last(): void
    {
        $this->assertSame('Xbc abc', Str::replaceFirst('a', 'X', 'abc abc'));
        $this->assertSame('abc Xbc', Str::replaceLast('a', 'X', 'abc abc'));
        $this->assertSame('abc', Str::replaceFirst('', 'X', 'abc'));
    }

    public function test_replace_start_and_end(): void
    {
        $this->assertSame('Xbc', Str::replaceStart('a', 'X', 'abc'));
        $this->assertSame('abc', Str::replaceStart('b', 'X', 'abc'));
        $this->assertSame('abX', Str::replaceEnd('c', 'X', 'abc'));
        $this->assertSame('abc', Str::replaceEnd('b', 'X', 'abc'));
    }

    public function test_replace_array(): void
    {
        $this->assertSame('1 and 2', Str::replaceArray('?', ['1', '2'], '? and ?'));
    }

    public function test_replace_matches(): void
    {
        $this->assertSame('a-b', Str::replaceMatches('/\d/', '-', 'a1b'));
        $this->assertSame('a[1]b', Str::replaceMatches('/\d/', fn ($m) => '[' . $m[0] . ']', 'a1b'));
    }

    public function test_remove_swap_and_reverse(): void
    {
        $this->assertSame('ac', Str::remove('b', 'abc'));
        $this->assertSame('ac', Str::remove('B', 'aBc'));
        $this->assertSame('Hi there', Str::swap(['Hello' => 'Hi', 'world' => 'there'], 'Hello world'));
        $this->assertSame('cba', Str::reverse('abc'));
    }

    public function test_squish_and_deduplicate(): void
    {
        $this->assertSame('a b c', Str::squish("  a   b \n c  "));
        $this->assertSame('a b c', Str::deduplicate('a  b   c'));
        $this->assertSame('a/b', Str::deduplicate('a///b', '/'));
    }

    public function test_wrap_and_unwrap(): void
    {
        $this->assertSame('"a"', Str::wrap('a', '"'));
        $this->assertSame('[a]', Str::wrap('a', '[', ']'));
        $this->assertSame('a', Str::unwrap('"a"', '"'));
        $this->assertSame('a', Str::unwrap('[a]', '[', ']'));
    }

    // ─── Str: padding ─────────────────────────────────────

    public function test_padding(): void
    {
        $this->assertSame('__abc', Str::padLeft('abc', 5, '_'));
        $this->assertSame('abc__', Str::padRight('abc', 5, '_'));
        $this->assertSame('_abc_', Str::padBoth('abc', 5, '_'));
        $this->assertSame('abc', Str::padLeft('abc', 2, '_'));
    }

    /** Padding counts characters, not bytes, so multi-byte text lines up. */
    public function test_padding_is_multibyte_aware(): void
    {
        $this->assertSame(5, mb_strlen(Str::padRight('Mü', 5, '_')));
    }

    // ─── Str: words and masking ───────────────────────────

    public function test_word_helpers(): void
    {
        $this->assertSame(3, Str::wordCount('one two three'));
        $this->assertSame("a\nb", Str::wordWrap('a b', 1, "\n"));
    }

    public function test_numbers(): void
    {
        $this->assertSame('1234', Str::numbers('a1b2c3d4'));
        $this->assertSame('', Str::numbers('abc'));
    }

    public function test_mask(): void
    {
        $this->assertSame('ab***', Str::mask('abcde', '*', 2));
        $this->assertSame('ab**e', Str::mask('abcde', '*', 2, 2));
        $this->assertSame('abc**', Str::mask('abcde', '*', -2));
    }

    public function test_base64_round_trip(): void
    {
        $this->assertSame('hello', Str::fromBase64(Str::toBase64('hello')));
    }

    // ─── Str: identifiers ─────────────────────────────────

    public function test_uuid7_is_well_formed_and_ordered(): void
    {
        $first = Str::uuid7();
        usleep(2000);
        $second = Str::uuid7();

        $this->assertTrue(Str::isUuid($first));
        $this->assertSame('7', $first[14], 'version nibble must be 7');
        $this->assertLessThan(0, strcmp($first, $second), 'v7 must sort by creation time');
    }

    public function test_ulid_is_well_formed(): void
    {
        $ulid = Str::ulid();

        $this->assertSame(26, strlen($ulid));
        $this->assertTrue(Str::isUlid($ulid));
    }

    public function test_password_length_and_charset(): void
    {
        $this->assertSame(16, strlen(Str::password(16)));
        $this->assertMatchesRegularExpression('/^[0-9]+$/', Str::password(10, false, true, false));
    }

    public function test_parse_callback(): void
    {
        $this->assertSame(['Foo', 'bar'], Str::parseCallback('Foo@bar'));
        $this->assertSame(['Foo', null], Str::parseCallback('Foo'));
        $this->assertSame(['Foo', 'x'], Str::parseCallback('Foo', 'x'));
    }

    // ─── Arr: shape ───────────────────────────────────────

    public function test_shape_helpers(): void
    {
        $this->assertTrue(Arr::accessible([1]));
        $this->assertFalse(Arr::accessible('str'));
        $this->assertTrue(Arr::isList([1, 2]));
        $this->assertFalse(Arr::isList(['a' => 1]));
    }

    public function test_dot_and_undot_round_trip(): void
    {
        $nested = ['mail' => ['from' => ['address' => 'a@b.test']], 'debug' => true];
        $dotted = Arr::dot($nested);

        $this->assertSame(['mail.from.address' => 'a@b.test', 'debug' => true], $dotted);
        $this->assertSame($nested, Arr::undot($dotted));
    }

    // ─── Arr: reading ─────────────────────────────────────

    public function test_has_all_and_has_any(): void
    {
        $data = ['a' => 1, 'b' => ['c' => 2]];

        $this->assertTrue(Arr::hasAll($data, ['a', 'b.c']));
        $this->assertFalse(Arr::hasAll($data, ['a', 'zz']));
        $this->assertTrue(Arr::hasAny($data, ['zz', 'a']));
        $this->assertFalse(Arr::hasAny($data, ['zz']));
    }

    public function test_pull_removes_the_key(): void
    {
        $data = ['a' => 1, 'b' => 2];

        $this->assertSame(1, Arr::pull($data, 'a'));
        $this->assertSame(['b' => 2], $data);
    }

    public function test_sole(): void
    {
        $this->assertSame(2, Arr::sole([1, 2, 3], fn ($v) => $v === 2));

        $this->expectException(\RuntimeException::class);
        Arr::sole([1, 2, 3], fn ($v) => $v > 1);
    }

    public function test_typed_reads(): void
    {
        $data = ['s' => 'x', 'i' => 5, 'f' => 1.5, 'b' => true];

        $this->assertSame('x', Arr::string($data, 's'));
        $this->assertSame(5, Arr::integer($data, 'i'));
        $this->assertSame(1.5, Arr::float($data, 'f'));
        $this->assertTrue(Arr::boolean($data, 'b'));

        $this->expectException(\UnexpectedValueException::class);
        Arr::string($data, 'i');
    }

    // ─── Arr: writing and reshaping ───────────────────────

    public function test_add_only_fills_gaps(): void
    {
        $this->assertSame(['a' => 1], Arr::add([], 'a', 1));
        $this->assertSame(['a' => 1], Arr::add(['a' => 1], 'a', 2));
    }

    public function test_prepend(): void
    {
        $this->assertSame([0, 1, 2], Arr::prepend([1, 2], 0));
        $this->assertSame(['k' => 0, 'a' => 1], Arr::prepend(['a' => 1], 0, 'k'));
        $this->assertSame(['p_a' => 1], Arr::prependKeysWith(['a' => 1], 'p_'));
    }

    public function test_collapse_and_divide(): void
    {
        $this->assertSame([1, 2, 3], Arr::collapse([[1], [2, 3]]));
        $this->assertSame([['a'], [1]], Arr::divide(['a' => 1]));
    }

    public function test_cross_join(): void
    {
        $this->assertSame(
            [[1, 'a'], [1, 'b'], [2, 'a'], [2, 'b']],
            Arr::crossJoin([1, 2], ['a', 'b'])
        );
    }

    public function test_key_by_map_and_map_with_keys(): void
    {
        $rows = [['id' => 1, 'n' => 'a'], ['id' => 2, 'n' => 'b']];

        $this->assertSame([1, 2], array_keys(Arr::keyBy($rows, 'id')));
        $this->assertSame(['a' => 1], Arr::mapWithKeys(['x' => 1], fn ($v, $k) => ['a' => $v]));
        $this->assertSame(['a' => 2], Arr::map(['a' => 1], fn ($v) => $v * 2));
    }

    public function test_partition(): void
    {
        [$even, $odd] = Arr::partition([1, 2, 3, 4], fn ($v) => $v % 2 === 0);

        $this->assertSame([2, 4], array_values($even));
        $this->assertSame([1, 3], array_values($odd));
    }

    // ─── Arr: filtering ───────────────────────────────────

    public function test_where_reject_and_where_not_null(): void
    {
        $this->assertSame([1 => 2], Arr::where([1, 2], fn ($v) => $v === 2));
        $this->assertSame([0 => 1], Arr::reject([1, 2], fn ($v) => $v === 2));
        $this->assertSame([0 => 1, 2 => 3], Arr::whereNotNull([1, null, 3]));
    }

    public function test_every_and_some(): void
    {
        $this->assertTrue(Arr::every([2, 4], fn ($v) => $v % 2 === 0));
        $this->assertFalse(Arr::every([2, 3], fn ($v) => $v % 2 === 0));
        $this->assertTrue(Arr::some([1, 2], fn ($v) => $v === 2));
        $this->assertFalse(Arr::some([1, 3], fn ($v) => $v === 2));
    }

    public function test_select(): void
    {
        $this->assertSame(
            [['id' => 1], ['id' => 2]],
            Arr::select([['id' => 1, 'x' => 9], ['id' => 2, 'x' => 8]], 'id')
        );
    }

    // ─── Arr: ordering and output ─────────────────────────

    public function test_sort_recursive(): void
    {
        $this->assertSame(
            ['a' => [1, 2], 'b' => [3]],
            Arr::sortRecursive(['b' => [3], 'a' => [2, 1]])
        );
    }

    public function test_arr_take(): void
    {
        $this->assertSame([1, 2], Arr::take([1, 2, 3], 2));
        $this->assertSame([2, 3], Arr::take([1, 2, 3], -2));
    }

    public function test_random_respects_the_count(): void
    {
        $this->assertContains(Arr::random([1, 2, 3]), [1, 2, 3]);
        $this->assertCount(2, Arr::random([1, 2, 3], 2));

        $this->expectException(\InvalidArgumentException::class);
        Arr::random([1], 5);
    }

    public function test_join(): void
    {
        $this->assertSame('a, b, c', Arr::join(['a', 'b', 'c'], ', '));
        $this->assertSame('a, b and c', Arr::join(['a', 'b', 'c'], ', ', ' and '));
        $this->assertSame('a', Arr::join(['a'], ', ', ' and '));
    }

    public function test_query(): void
    {
        $this->assertSame('a=1&b=2', Arr::query(['a' => 1, 'b' => 2]));
    }

    // ─── Stringable argument order ────────────────────────

    /**
     * Several Str methods take the subject last. Forwarding those positionally
     * would pass the subject as the pattern and quietly return the wrong
     * answer, so each has an explicit fluent form.
     */
    public function test_fluent_methods_whose_static_form_takes_the_subject_last(): void
    {
        $this->assertSame('hello-world', (string) str('Hello World')->slug());
        $this->assertSame('a+b', (string) str('a-b')->replace('-', '+'));
        $this->assertSame('Xbc', (string) str('abc')->replaceStart('a', 'X'));
        $this->assertSame('abX', (string) str('abc')->replaceEnd('c', 'X'));
        $this->assertSame('ac', (string) str('abc')->remove('b'));
        $this->assertSame('Hi world', (string) str('Hello world')->swap(['Hello' => 'Hi']));
        $this->assertSame('1 and 2', (string) str('? and ?')->replaceArray('?', ['1', '2']));
        $this->assertSame('a-b', (string) str('a1b')->replaceMatches('/\d/', '-'));
    }

    public function test_fluent_pattern_methods(): void
    {
        $this->assertTrue(str('foo.bar')->is('foo.*'));
        $this->assertFalse(str('baz.bar')->is('foo.*'));
        $this->assertTrue(str('abc')->isMatch('/^a/'));
        $this->assertSame('bar', (string) str('foo bar')->match('/foo (\w+)/'));
        $this->assertSame(['a', 'b'], str('a b')->matchAll('/(\w)/'));
    }

    public function test_fluent_chaining_still_forwards(): void
    {
        $this->assertSame('HELLO_WORLD', (string) str('Hello World')->slug()->upper()->replace('-', '_'));
        $this->assertSame('Padded', (string) str('  padded  ')->squish()->headline());
    }

    /** A Str method with no subject argument is not a fluent operation. */
    public function test_fluent_wrapper_rejects_static_factories(): void
    {
        $this->expectException(\BadMethodCallException::class);

        str('x')->password(8);
    }

    public function test_fluent_wrapper_rejects_unknown_methods(): void
    {
        $this->expectException(\BadMethodCallException::class);

        str('x')->noSuchMethod();
    }
}
