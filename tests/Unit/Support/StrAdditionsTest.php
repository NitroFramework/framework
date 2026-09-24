<?php

namespace Tests\Unit\Support;

use BadMethodCallException;
use Nitro\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * The string operations that were absent, and the caches behind the case
 * conversions.
 */
class StrAdditionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Str::flushMacros();
        Str::flushCache();

        parent::tearDown();
    }

    // ─── APA title case ───────────────────────────────────

    /**
     * Differs from title() in that a short joining word stays lowercase. It is
     * what a heading or a citation is supposed to look like, where title()
     * capitalises 'Of' and 'The' in the middle of a sentence.
     */
    public function test_short_joining_words_stay_lowercase(): void
    {
        $this->assertSame('A Nice Title Uses the Correct Case', Str::apa('a nice title uses the correct case'));
        $this->assertSame('Of Mice and Men', Str::apa('of mice and men'));
        $this->assertSame('Tom and Jerry: A Tale of Two Cats', Str::apa('tom and jerry: a tale of two cats'));
    }

    /** Opening the title, or following punctuation, capitalises even those. */
    public function test_a_minor_word_after_punctuation_is_capitalised(): void
    {
        $this->assertSame('The End. And Then?', Str::apa('the end. and then?'));
        $this->assertSame('The Quick Brown Fox', Str::apa('the quick brown fox'));
    }

    public function test_each_part_of_a_hyphenated_word_is_considered(): void
    {
        $this->assertSame('The Story of the Man-in-the-Middle', Str::apa('the story of the man-in-the-middle'));
        $this->assertSame('Well-Behaved Women Seldom Make History', Str::apa('well-behaved women seldom make history'));
    }

    public function test_an_empty_title_is_returned_unchanged(): void
    {
        $this->assertSame('', Str::apa(''));
        $this->assertSame('   ', Str::apa('   '));
    }

    // ─── counted ──────────────────────────────────────────

    public function test_counted_puts_the_number_in_front(): void
    {
        $this->assertSame('1 comment', Str::counted('comment', 1));
        $this->assertSame('3 comments', Str::counted('comment', 3));
        $this->assertSame('0 comments', Str::counted('comment', 0));
    }

    public function test_counted_inflects_irregular_words(): void
    {
        $this->assertSame('2 children', Str::counted('child', 2));
        $this->assertSame('2 categories', Str::counted('category', 2));
        $this->assertSame('2 sheep', Str::counted('sheep', 2));
    }

    public function test_counted_accepts_the_collection_being_counted(): void
    {
        $this->assertSame('3 comments', Str::counted('comment', ['a', 'b', 'c']));
    }

    /** The new argument must not have changed plural() itself. */
    public function test_plural_without_the_count_is_unchanged(): void
    {
        $this->assertSame('comments', Str::plural('comment'));
        $this->assertSame('comment', Str::plural('comment', 1));
        $this->assertSame('categories', Str::plural('category', 2));
    }

    // ─── transliterate ────────────────────────────────────

    public function test_accented_latin_becomes_ascii_keeping_its_case(): void
    {
        $this->assertSame('Cafe', Str::transliterate('Café'));
        $this->assertSame('cafe', Str::transliterate('café'));
        $this->assertSame('Etat', Str::transliterate('État'));
    }

    /** An uppercase letter expanding to two is uppercase throughout. */
    public function test_an_uppercase_expansion_is_not_title_cased(): void
    {
        $this->assertSame('AEroskobing', Str::transliterate('Ærøskøbing'));
        $this->assertSame('straße', 'straße');
        $this->assertSame('strasse', Str::transliterate('straße'));
    }

    /** Unlike ascii(), an unmappable character does not pass through. */
    public function test_an_unmappable_character_is_replaced(): void
    {
        $this->assertSame('???', Str::transliterate('日本語'));
        $this->assertSame('', Str::transliterate('日本語', ''));
        $this->assertSame('-a-', Str::transliterate('日a語', '-'));
    }

    public function test_already_ascii_input_can_be_left_alone(): void
    {
        $this->assertSame('plain ascii', Str::transliterate('plain ascii', '?', true));
        $this->assertSame('plain ascii', Str::transliterate('plain ascii'));
    }

    // ─── Markdown ─────────────────────────────────────────

    public function test_markdown_renders_blocks(): void
    {
        $html = Str::markdown("# Title\n\nSome **bold** text.");

        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    /** For a fragment going inside a paragraph, where a <p> would be wrong. */
    public function test_inline_markdown_adds_no_block_wrapper(): void
    {
        $html = Str::inlineMarkdown('Some **bold** text.');

        $this->assertSame('Some <strong>bold</strong> text.', $html);
    }

    public function test_raw_html_is_escaped_unless_allowed(): void
    {
        $this->assertStringContainsString('&lt;script&gt;', Str::inlineMarkdown('<script>x</script>'));
        $this->assertStringContainsString('<span>', Str::inlineMarkdown('<span>x</span>', ['allow_html' => true]));
    }

    // ─── Macros ───────────────────────────────────────────

    /**
     * Str is never an instance, so a macro on it reached __call and was
     * unreachable. It is registered against the class, so it has to dispatch
     * from a static call.
     */
    public function test_a_macro_is_callable_statically(): void
    {
        Str::macro('shout', static fn (string $value): string => strtoupper($value) . '!');

        $this->assertTrue(Str::hasMacro('shout'));
        $this->assertSame('HI!', Str::shout('hi'));
    }

    public function test_a_mixin_registers_a_whole_group_of_helpers(): void
    {
        Str::mixin(new StrTestMixin());

        $this->assertSame('cba', Str::reversed('abc'));
        $this->assertSame('a-b', Str::dashed('a b'));
    }

    public function test_flushing_forgets_every_macro(): void
    {
        Str::macro('shout', static fn (string $value): string => strtoupper($value));

        Str::flushMacros();

        $this->assertFalse(Str::hasMacro('shout'));
    }

    public function test_an_unknown_static_call_says_so(): void
    {
        $this->expectException(BadMethodCallException::class);

        Str::thisWasNeverRegistered('x');
    }

    // ─── Case caches ──────────────────────────────────────

    /** Cached or computed, the answer has to be the same one. */
    public function test_a_cached_conversion_matches_the_computed_one(): void
    {
        foreach (['fooBar', 'foo_bar', 'FooBar', 'foo-bar', 'FOO_BAR', 'user_id'] as $value) {
            $first = [Str::snake($value), Str::camel($value), Str::studly($value)];

            Str::flushCache();

            $second = [Str::snake($value), Str::camel($value), Str::studly($value)];

            $this->assertSame($second, $first, "cached and computed differ for [{$value}]");
            $this->assertSame($first, [Str::snake($value), Str::camel($value), Str::studly($value)]);
        }
    }

    /** The delimiter is part of the key, or snake('x', '-') returns snake('x'). */
    public function test_the_snake_cache_keys_on_the_delimiter(): void
    {
        $this->assertSame('foo_bar', Str::snake('fooBar'));
        $this->assertSame('foo-bar', Str::snake('fooBar', '-'));
        $this->assertSame('foo_bar', Str::snake('fooBar'));
    }

    public function test_an_already_lowercase_value_is_cached_too(): void
    {
        $this->assertSame('foo', Str::snake('foo'));
        $this->assertSame('foo', Str::snake('foo'));
        $this->assertSame('foo', Str::snake('foo', '-'));
    }

    /**
     * Distinct input must not accumulate. A worker passing a request parameter
     * through snake() would otherwise hold every value it had ever seen —
     * measured at 28MB over sixty thousand of them before the cache was bound.
     */
    public function test_the_caches_do_not_grow_without_bound(): void
    {
        Str::flushCache();

        $before = memory_get_usage();

        for ($i = 0; $i < 20000; $i++) {
            Str::snake('someColumnName' . $i);
            Str::studly('some_column_name' . $i);
            Str::camel('some_column_name' . $i);
        }

        $held = memory_get_usage() - $before;

        $this->assertLessThan(2 * 1048576, $held, 'the caches held ' . round($held / 1048576, 1) . 'MB');

        // Still correct once it has been dropped and refilled.
        $this->assertSame('some_column_name', Str::snake('someColumnName'));
        $this->assertSame('SomeColumnName', Str::studly('some_column_name'));
        $this->assertSame('someColumnName', Str::camel('some_column_name'));
    }
}

/** Methods returning the closure to register, which is what mixin() reads. */
class StrTestMixin
{
    public function reversed(): \Closure
    {
        return static fn (string $value): string => strrev($value);
    }

    public function dashed(): \Closure
    {
        return static fn (string $value): string => str_replace(' ', '-', $value);
    }
}
