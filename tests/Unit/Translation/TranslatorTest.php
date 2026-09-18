<?php

namespace Tests\Unit\Translation;

use Nitro\Translation\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Looking up translated lines, replacing placeholders and choosing plurals.
 */
class TranslatorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/nitro-lang-' . getmypid() . '-' . uniqid();

        @mkdir($this->path . '/en', 0755, true);
        @mkdir($this->path . '/fr', 0755, true);

        file_put_contents($this->path . '/en/messages.php', '<?php return ' . var_export([
            'welcome' => 'Welcome, :name',
            'shout' => 'Hello :NAME',
            'title' => 'Dear :Name',
            'nested' => ['deep' => 'Found it'],
            'items' => '{0} none|[1,1] one item|[2,*] :count items',
            'simple' => 'apple|apples',
        ], true) . ';');

        file_put_contents($this->path . '/fr/messages.php', '<?php return ' . var_export([
            'welcome' => 'Bienvenue, :name',
        ], true) . ';');

        file_put_contents($this->path . '/en.json', json_encode([
            'Good morning' => 'Good morning',
        ]));
    }

    protected function tearDown(): void
    {
        foreach (['en/messages.php', 'fr/messages.php', 'en.json'] as $file) {
            @unlink($this->path . '/' . $file);
        }

        @rmdir($this->path . '/en');
        @rmdir($this->path . '/fr');
        @rmdir($this->path);

        parent::tearDown();
    }

    private function translator(string $locale = 'en', string $fallback = 'en'): Translator
    {
        return new Translator($this->path, $locale, $fallback);
    }

    // ─── Lookup ───────────────────────────────────────────

    public function test_a_line_is_found_and_its_placeholder_replaced(): void
    {
        $this->assertSame(
            'Welcome, Ada',
            $this->translator()->get('messages.welcome', ['name' => 'Ada'])
        );
    }

    public function test_placeholder_case_is_followed(): void
    {
        $translator = $this->translator();

        $this->assertSame('Hello ADA', $translator->get('messages.shout', ['name' => 'Ada']));
        $this->assertSame('Dear Ada', $translator->get('messages.title', ['name' => 'ada']));
    }

    public function test_a_nested_line_is_addressed_with_dots(): void
    {
        $this->assertSame('Found it', $this->translator()->get('messages.nested.deep'));
    }

    public function test_a_missing_line_comes_back_as_its_key(): void
    {
        $translator = $this->translator();

        $this->assertSame('messages.absent', $translator->get('messages.absent'));
        $this->assertFalse($translator->has('messages.absent'));
        $this->assertTrue($translator->has('messages.welcome'));
    }

    public function test_a_missing_group_does_not_raise(): void
    {
        $this->assertSame('nothing.here', $this->translator()->get('nothing.here'));
    }

    public function test_json_lines_are_keyed_by_the_sentence(): void
    {
        $this->assertSame('Good morning', $this->translator()->get('Good morning'));
    }

    // ─── Locales ──────────────────────────────────────────

    public function test_the_locale_decides_which_file_is_read(): void
    {
        $this->assertSame(
            'Bienvenue, Ada',
            $this->translator('fr')->get('messages.welcome', ['name' => 'Ada'])
        );
    }

    public function test_a_line_absent_from_the_locale_falls_back(): void
    {
        $this->assertSame('Found it', $this->translator('fr', 'en')->get('messages.nested.deep'));
    }

    public function test_the_locale_can_be_changed(): void
    {
        $translator = $this->translator();

        $this->assertSame('en', $translator->getLocale());
        $this->assertTrue($translator->isLocale('en'));

        $translator->setLocale('fr');

        $this->assertSame('fr', $translator->getLocale());
        $this->assertFalse($translator->isLocale('en'));
    }

    public function test_the_fallback_can_be_changed(): void
    {
        $translator = $this->translator();

        $this->assertSame('en', $translator->getFallback());

        $translator->setFallback('fr');

        $this->assertSame('fr', $translator->getFallback());
    }

    // ─── Plurals ──────────────────────────────────────────

    public function test_an_explicit_count_is_matched(): void
    {
        $this->assertSame('none', $this->translator()->choice('messages.items', 0));
    }

    public function test_an_explicit_range_is_matched(): void
    {
        $translator = $this->translator();

        $this->assertSame('one item', $translator->choice('messages.items', 1));
        $this->assertSame('5 items', $translator->choice('messages.items', 5));
        $this->assertSame('99 items', $translator->choice('messages.items', 99));
    }

    public function test_plain_forms_fall_back_to_singular_and_plural(): void
    {
        $translator = $this->translator();

        $this->assertSame('apple', $translator->choice('messages.simple', 1));
        $this->assertSame('apples', $translator->choice('messages.simple', 4));
        $this->assertSame('apples', $translator->choice('messages.simple', 0));
    }

    public function test_count_is_available_to_the_chosen_form(): void
    {
        $this->assertSame('7 items', $this->translator()->choice('messages.items', 7));
    }

    // ─── Runtime lines ────────────────────────────────────

    public function test_lines_can_be_added_without_a_file(): void
    {
        $translator = $this->translator();

        $translator->addLines(['runtime' => 'Added later'], 'en', 'messages');

        $this->assertSame('Added later', $translator->get('messages.runtime'));
    }

    public function test_added_lines_do_not_replace_the_file(): void
    {
        $translator = $this->translator();

        $translator->get('messages.welcome', ['name' => 'Ada']);
        $translator->addLines(['runtime' => 'Added later'], 'en', 'messages');

        $this->assertSame('Welcome, Ada', $translator->get('messages.welcome', ['name' => 'Ada']));
        $this->assertSame('Added later', $translator->get('messages.runtime'));
    }
}
