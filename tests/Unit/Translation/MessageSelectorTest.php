<?php

namespace Tests\Unit\Translation;

use Nitro\Translation\MessageSelector;
use PHPUnit\Framework\TestCase;

/**
 * Choosing a plural form in languages that do not work like English.
 *
 * The cases below are the ones an English-only rule gets wrong: a language
 * with one form, with three, with six, and one where the form depends on the
 * last two digits rather than on whether the count is 1.
 */
class MessageSelectorTest extends TestCase
{
    private MessageSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->selector = new MessageSelector();
    }

    // ─── Two forms ────────────────────────────────────────

    public function test_english_splits_at_one(): void
    {
        $line = 'apple|apples';

        $this->assertSame('apples', $this->selector->choose($line, 0, 'en'));
        $this->assertSame('apple', $this->selector->choose($line, 1, 'en'));
        $this->assertSame('apples', $this->selector->choose($line, 2, 'en'));
    }

    /** French counts zero with the singular, where English does not. */
    public function test_french_counts_zero_as_singular(): void
    {
        $line = 'pomme|pommes';

        $this->assertSame('pomme', $this->selector->choose($line, 0, 'fr'));
        $this->assertSame('pomme', $this->selector->choose($line, 1, 'fr'));
        $this->assertSame('pommes', $this->selector->choose($line, 2, 'fr'));
    }

    // ─── One form ─────────────────────────────────────────

    public function test_a_language_with_one_form_always_takes_the_first(): void
    {
        $line = 'first|second|third';

        foreach ([0, 1, 2, 5, 21, 100] as $number) {
            $this->assertSame('first', $this->selector->choose($line, $number, 'ja'));
        }
    }

    // ─── Three forms ──────────────────────────────────────

    /**
     * Russian chooses by the last digit, so 21 takes the same form as 1 and
     * 5 takes a third form English has no name for.
     */
    public function test_russian_chooses_by_the_last_digit(): void
    {
        $line = 'файл|файла|файлов';

        $this->assertSame('файлов', $this->selector->choose($line, 0, 'ru'));
        $this->assertSame('файл', $this->selector->choose($line, 1, 'ru'));
        $this->assertSame('файла', $this->selector->choose($line, 2, 'ru'));
        $this->assertSame('файлов', $this->selector->choose($line, 5, 'ru'));
        $this->assertSame('файлов', $this->selector->choose($line, 11, 'ru'));
        $this->assertSame('файл', $this->selector->choose($line, 21, 'ru'));
        $this->assertSame('файла', $this->selector->choose($line, 22, 'ru'));
        $this->assertSame('файлов', $this->selector->choose($line, 25, 'ru'));
    }

    public function test_czech_has_a_form_for_two_to_four(): void
    {
        $line = 'soubor|soubory|souborů';

        $this->assertSame('soubor', $this->selector->choose($line, 1, 'cs'));
        $this->assertSame('soubory', $this->selector->choose($line, 3, 'cs'));
        $this->assertSame('souborů', $this->selector->choose($line, 5, 'cs'));
    }

    // ─── Six forms ────────────────────────────────────────

    public function test_arabic_has_six_forms(): void
    {
        $line = 'zero|one|two|few|many|other';

        $this->assertSame('zero', $this->selector->choose($line, 0, 'ar'));
        $this->assertSame('one', $this->selector->choose($line, 1, 'ar'));
        $this->assertSame('two', $this->selector->choose($line, 2, 'ar'));
        $this->assertSame('few', $this->selector->choose($line, 3, 'ar'));
        $this->assertSame('many', $this->selector->choose($line, 11, 'ar'));
        $this->assertSame('other', $this->selector->choose($line, 100, 'ar'));
    }

    // ─── Fewer forms than the rule wants ──────────────────

    /**
     * A line with one form is that form, and a rule asking for a form that was
     * never written falls back to the first rather than to nothing.
     */
    public function test_a_line_with_too_few_forms_falls_back_to_the_first(): void
    {
        $this->assertSame('only', $this->selector->choose('only', 5, 'ru'));
        $this->assertSame('one', $this->selector->choose('one|two', 5, 'ru'));
    }

    // ─── Explicit ranges ──────────────────────────────────

    public function test_an_explicit_count_wins_over_the_rule(): void
    {
        $line = '{0} none|[1,19] some|[20,*] lots';

        $this->assertSame('none', $this->selector->choose($line, 0, 'en'));
        $this->assertSame('some', $this->selector->choose($line, 1, 'en'));
        $this->assertSame('some', $this->selector->choose($line, 19, 'en'));
        $this->assertSame('lots', $this->selector->choose($line, 20, 'en'));
        $this->assertSame('lots', $this->selector->choose($line, 5000, 'en'));
    }

    public function test_a_range_may_be_open_on_the_low_side(): void
    {
        $line = '[*,5] low|[6,*] high';

        $this->assertSame('low', $this->selector->choose($line, 0, 'en'));
        $this->assertSame('low', $this->selector->choose($line, 5, 'en'));
        $this->assertSame('high', $this->selector->choose($line, 6, 'en'));
    }

    /** An explicit range in a language whose own rule would choose otherwise. */
    public function test_an_explicit_range_applies_in_any_language(): void
    {
        $line = '{0} ноль|[1,*] много';

        $this->assertSame('ноль', $this->selector->choose($line, 0, 'ru'));
        $this->assertSame('много', $this->selector->choose($line, 5, 'ru'));
    }

    // ─── Locale matching ──────────────────────────────────

    public function test_a_regional_locale_follows_its_language(): void
    {
        $this->assertSame(
            $this->selector->getPluralIndex('ru', 5),
            $this->selector->getPluralIndex('ru_RU', 5),
        );
    }

    /**
     * A locale the table does not name at all — 'en-GB' as a browser writes
     * it, or a region nobody listed — follows its language rather than
     * silently collapsing to one form.
     */
    public function test_an_unlisted_region_still_follows_its_language(): void
    {
        $this->assertSame('apples', $this->selector->choose('apple|apples', 2, 'en-GB'));
        $this->assertSame('файлов', $this->selector->choose('файл|файла|файлов', 5, 'ru_XX'));
    }

    public function test_an_unknown_language_takes_the_first_form(): void
    {
        $this->assertSame('apple', $this->selector->choose('apple|apples', 5, 'xx'));
    }

    // ─── The table itself ─────────────────────────────────

    public function test_the_table_covers_every_locale_it_claims(): void
    {
        $locales = MessageSelector::knownLocales();

        $this->assertCount(280, $locales);
        $this->assertContains('en', $locales);
        $this->assertContains('ar_EG', $locales);
        $this->assertSame($locales, array_unique($locales), 'no locale should be listed twice');
    }
}
