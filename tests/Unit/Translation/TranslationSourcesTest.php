<?php

namespace Tests\Unit\Translation;

use Nitro\Translation\ArrayLoader;
use Nitro\Translation\FileLoader;
use Nitro\Translation\Translator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Where lines come from, beyond one directory of PHP files.
 *
 * A package ships its own translations and must be able to say so without the
 * application copying them; the application must be able to override one of
 * those lines without restating the rest; a test must be able to translate
 * without a directory at all. None of that was reachable while the translator
 * read a single hardcoded path itself.
 */
class TranslationSourcesTest extends TestCase
{
    private string $root;

    private string $package;

    protected function setUp(): void
    {
        parent::setUp();

        $unique = getmypid() . '-' . uniqid();

        $this->root = sys_get_temp_dir() . '/nitro-src-' . $unique;
        $this->package = sys_get_temp_dir() . '/nitro-pkg-' . $unique;

        $this->write($this->root . '/en/messages.php', [
            'welcome' => 'Welcome, :name',
            'overlap' => ':name and :name_full',
            'group' => ['a' => 'A :name', 'b' => 'B :name'],
        ]);

        $this->write($this->package . '/en/invoice.php', [
            'paid' => 'Invoice :number is paid',
            'due' => 'Invoice :number is due',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->package] as $directory) {
            $this->remove($directory);
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $lines */
    private function write(string $path, array $lines): void
    {
        @mkdir(dirname($path), 0755, true);

        file_put_contents($path, '<?php return ' . var_export($lines, true) . ';');
    }

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            is_dir($path) ? $this->remove($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    private function translator(): Translator
    {
        return new Translator(new FileLoader($this->root), 'en', 'en');
    }

    // ─── Package namespaces ───────────────────────────────

    public function test_a_package_can_register_its_own_directory(): void
    {
        $translator = $this->translator()->addNamespace('billing', $this->package);

        $this->assertSame(
            'Invoice 7 is paid',
            $translator->get('billing::invoice.paid', ['number' => 7]),
        );
    }

    /** One line overridden, the rest of the package's group still in place. */
    public function test_an_application_can_override_one_of_a_packages_lines(): void
    {
        $this->write($this->root . '/vendor/billing/en/invoice.php', [
            'due' => 'Invoice :number needs paying',
        ]);

        $translator = $this->translator()->addNamespace('billing', $this->package);

        $this->assertSame('Invoice 7 needs paying', $translator->get('billing::invoice.due', ['number' => 7]));
        $this->assertSame('Invoice 7 is paid', $translator->get('billing::invoice.paid', ['number' => 7]));
    }

    public function test_an_unregistered_namespace_returns_the_key(): void
    {
        $this->assertSame('nothing::invoice.paid', $this->translator()->get('nothing::invoice.paid'));
    }

    // ─── Several directories ──────────────────────────────

    /** A later directory replaces keys from an earlier one, not the file. */
    public function test_a_second_directory_overrides_line_by_line(): void
    {
        $this->write($this->package . '/en/messages.php', ['welcome' => 'Hi there, :name']);

        $loader = new FileLoader($this->root);
        $loader->addPath($this->package);

        $translator = new Translator($loader, 'en', 'en');

        $this->assertSame('Hi there, Ada', $translator->get('messages.welcome', ['name' => 'Ada']));
        $this->assertSame('A Ada', $translator->get('messages.group.a', ['name' => 'Ada']));
    }

    // ─── JSON ─────────────────────────────────────────────

    public function test_json_lines_are_found_in_an_added_json_path(): void
    {
        @mkdir($this->package, 0755, true);

        file_put_contents($this->package . '/en.json', json_encode(['Sign out' => 'Log out']));

        $loader = new FileLoader($this->root);
        $loader->addJsonPath($this->package);

        $this->assertSame('Log out', (new Translator($loader, 'en', 'en'))->get('Sign out'));
    }

    /** A file that is present and unreadable is a mistake worth hearing about. */
    public function test_invalid_json_throws_rather_than_translating_to_nothing(): void
    {
        file_put_contents($this->root . '/en.json', '{ not json');

        $this->expectException(RuntimeException::class);

        $this->translator()->get('anything');
    }

    // ─── In memory ────────────────────────────────────────

    public function test_a_test_can_translate_without_touching_disk(): void
    {
        $loader = (new ArrayLoader())->addMessages('en', 'messages', ['welcome' => 'Welcome, :name']);

        $this->assertSame('Welcome, Ada', (new Translator($loader, 'en', 'en'))->get('messages.welcome', ['name' => 'Ada']));
    }

    /**
     * Adding a directory to a loader that reads none must say so, rather than
     * accepting it and quietly translating nothing from it.
     */
    public function test_adding_a_path_to_an_in_memory_loader_is_refused(): void
    {
        $this->expectException(\BadMethodCallException::class);

        (new Translator(new ArrayLoader(), 'en', 'en'))->addPath($this->package);
    }

    // ─── Groups ───────────────────────────────────────────

    /** A key naming a group returns the group, with placeholders replaced. */
    public function test_a_key_may_address_a_group_of_lines(): void
    {
        $this->assertSame(
            ['a' => 'A Ada', 'b' => 'B Ada'],
            $this->translator()->array('messages.group', ['name' => 'Ada']),
        );
    }

    public function test_asking_for_a_string_and_getting_a_group_is_an_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->translator()->string('messages.group');
    }

    // ─── Placeholders ─────────────────────────────────────

    /**
     * ':name' must not eat the front of ':name_full'. Replacing one at a time
     * did, because the shorter placeholder was substituted first.
     */
    public function test_a_placeholder_that_prefixes_another_is_not_eaten(): void
    {
        $this->assertSame(
            'Ada and Ada Lovelace',
            $this->translator()->get('messages.overlap', ['name' => 'Ada', 'name_full' => 'Ada Lovelace']),
        );
    }

    public function test_a_closure_replacement_wraps_part_of_the_line(): void
    {
        $this->write($this->root . '/en/messages.php', ['tagged' => 'Click <link>here</link> now']);

        $line = $this->translator()->get('messages.tagged', [
            'link' => static fn (string $inner): string => '<a href="#">' . $inner . '</a>',
        ]);

        $this->assertSame('Click <a href="#">here</a> now', $line);
    }

    // ─── Missing keys ─────────────────────────────────────

    public function test_a_missing_key_can_be_reported(): void
    {
        $seen = [];

        $translator = $this->translator()->handleMissingKeysUsing(
            function (string $key) use (&$seen): void {
                $seen[] = $key;
            }
        );

        $this->assertSame('messages.absent', $translator->get('messages.absent'));
        $this->assertSame(['messages.absent'], $seen);
    }

    /** The handler may supply the line, for a locale generated on demand. */
    public function test_a_missing_key_handler_may_supply_the_line(): void
    {
        $translator = $this->translator()->handleMissingKeysUsing(
            static fn (string $key): string => 'generated:' . $key
        );

        $this->assertSame('generated:messages.absent', $translator->get('messages.absent'));
    }

    /** Asking whether a key exists must not report it as missing. */
    public function test_has_does_not_fire_the_missing_key_handler(): void
    {
        $fired = 0;

        $translator = $this->translator()->handleMissingKeysUsing(function () use (&$fired): void {
            $fired++;
        });

        $translator->has('messages.absent');

        $this->assertSame(0, $fired);
    }

    /** A handler that translates something itself must not re-enter. */
    public function test_a_handler_that_translates_does_not_recurse(): void
    {
        $calls = 0;

        $translator = $this->translator();

        $translator->handleMissingKeysUsing(function (string $key) use (&$calls, $translator): void {
            $calls++;

            $translator->get('messages.also.absent');
        });

        $translator->get('messages.absent');

        $this->assertSame(1, $calls);
    }

    // ─── The locale chain ─────────────────────────────────

    public function test_the_locale_chain_can_be_decided_by_the_application(): void
    {
        $this->write($this->root . '/pt/messages.php', ['welcome' => 'Bem-vindo, :name']);

        $translator = new Translator(new FileLoader($this->root), 'pt_BR', 'en');

        $this->assertSame('Welcome, Ada', $translator->get('messages.welcome', ['name' => 'Ada']));

        $translator->determineLocalesUsing(static fn (string $locale): array => [$locale, 'pt', 'en']);

        $this->assertSame('Bem-vindo, Ada', $translator->get('messages.welcome', ['name' => 'Ada']));
    }

    // ─── Counting ─────────────────────────────────────────

    /** trans_choice() has always advertised this; it used to be a TypeError. */
    public function test_the_count_may_be_the_collection_being_counted(): void
    {
        $this->write($this->root . '/en/messages.php', ['items' => 'one item|:count items']);

        $translator = $this->translator();

        $this->assertSame('one item', $translator->choice('messages.items', ['a']));
        $this->assertSame('3 items', $translator->choice('messages.items', ['a', 'b', 'c']));
        $this->assertSame('3 items', $translator->choice('messages.items', new \ArrayObject(['a', 'b', 'c'])));
    }

    /**
     * The plural rule has to match the language the line is written in. A
     * Russian line falling back to English would be split by Russian's rule
     * and pick a form that is not there.
     */
    public function test_a_fallen_back_line_uses_the_fallback_locales_rule(): void
    {
        $this->write($this->root . '/en/messages.php', ['items' => 'one item|:count items']);

        $translator = new Translator(new FileLoader($this->root), 'ru', 'en');

        $this->assertSame('5 items', $translator->choice('messages.items', 5));
    }
}
