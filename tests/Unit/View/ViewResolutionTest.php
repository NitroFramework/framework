<?php

namespace Tests\Unit\View;

use InvalidArgumentException;
use Nitro\Foundation\Application;
use Nitro\View\Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Where a view name is looked for, and in what order.
 *
 * A namespace used to resolve against exactly one directory, which meant an
 * application could not override a view a package shipped — publishing a copy
 * had nowhere to go. Directories are now an ordered list and the first match
 * wins, so registering ahead of a package overrides it and the package's own
 * file stays as the fallback.
 */
class ViewResolutionTest extends TestCase
{
    private string $tmp;
    private Factory $views;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/nitro_view_res_' . bin2hex(random_bytes(4));

        foreach (['app', 'package', 'theme'] as $dir) {
            mkdir($this->tmp . '/' . $dir, 0755, true);
        }

        $application = new Application(dirname(__DIR__, 3));
        $application->bootstrap();

        restore_error_handler();
        restore_exception_handler();

        $this->views = $application->getContainer()->resolve(Factory::class);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*/*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->tmp . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }

        @rmdir($this->tmp);

        parent::tearDown();
    }

    private function write(string $dir, string $name, string $contents): string
    {
        $path = $this->tmp . '/' . $dir . '/' . $name . '.blade.php';

        file_put_contents($path, $contents);

        return $path;
    }

    private function dir(string $name): string
    {
        return $this->tmp . '/' . $name;
    }

    // ─── Namespaces ───────────────────────────────────────

    public function test_a_namespace_resolves_against_its_directory(): void
    {
        $this->write('package', 'banner', 'from the package');

        $this->views->addNamespace('pkg', $this->dir('package'));

        $this->assertSame('from the package', $this->views->make('pkg::banner')->render());
    }

    /** The point of the ordered list. */
    public function test_a_directory_registered_first_overrides_a_later_one(): void
    {
        $this->write('package', 'banner', 'from the package');
        $this->write('app', 'banner', 'from the application');

        $this->views->addNamespace('pkg', [$this->dir('app'), $this->dir('package')]);

        $this->assertSame('from the application', $this->views->make('pkg::banner')->render());
    }

    public function test_a_view_the_override_does_not_have_falls_through(): void
    {
        $this->write('package', 'only-here', 'from the package');

        $this->views->addNamespace('pkg', [$this->dir('app'), $this->dir('package')]);

        $this->assertSame('from the package', $this->views->make('pkg::only-here')->render());
    }

    public function test_add_namespace_appends_rather_than_replaces(): void
    {
        $this->write('package', 'banner', 'from the package');
        $this->write('theme', 'extra', 'from the theme');

        $this->views->addNamespace('pkg', $this->dir('package'));
        $this->views->addNamespace('pkg', $this->dir('theme'));

        $this->assertSame('from the package', $this->views->make('pkg::banner')->render());
        $this->assertSame('from the theme', $this->views->make('pkg::extra')->render());
    }

    public function test_prepend_namespace_wins_over_what_is_already_registered(): void
    {
        $this->write('package', 'banner', 'from the package');
        $this->write('theme', 'banner', 'from the theme');

        $this->views->addNamespace('pkg', $this->dir('package'));
        $this->views->prependNamespace('pkg', $this->dir('theme'));

        $this->assertSame('from the theme', $this->views->make('pkg::banner')->render());
    }

    public function test_replace_namespace_discards_the_previous_directories(): void
    {
        $this->write('package', 'banner', 'from the package');
        $this->write('theme', 'banner', 'from the theme');

        $this->views->addNamespace('pkg', $this->dir('package'));
        $this->views->replaceNamespace('pkg', $this->dir('theme'));

        $this->assertSame('from the theme', $this->views->make('pkg::banner')->render());
    }

    /** Registering an override after a view was already resolved must take effect. */
    public function test_the_resolved_path_cache_is_flushed_when_the_order_changes(): void
    {
        $this->write('package', 'banner', 'from the package');

        $this->views->addNamespace('pkg', $this->dir('package'));
        $this->assertSame('from the package', $this->views->make('pkg::banner')->render());

        $this->write('theme', 'banner', 'from the theme');
        $this->views->prependNamespace('pkg', $this->dir('theme'));

        $this->assertSame('from the theme', $this->views->make('pkg::banner')->render());
    }

    public function test_an_unregistered_namespace_says_so(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'nope::' is not registered");

        $this->views->make('nope::banner')->render();
    }

    /** A failure has to name every directory it looked in, or it cannot be diagnosed. */
    public function test_a_missing_view_reports_every_path_searched(): void
    {
        $this->views->addNamespace('pkg', [$this->dir('app'), $this->dir('package')]);

        try {
            $this->views->make('pkg::absent')->render();
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($this->dir('app'), $exception->getMessage());
            $this->assertStringContainsString($this->dir('package'), $exception->getMessage());
        }
    }

    // ─── first() ──────────────────────────────────────────

    public function test_first_takes_the_earliest_view_that_exists(): void
    {
        $this->write('package', 'fallback', 'the fallback');

        $this->views->addNamespace('pkg', $this->dir('package'));

        $this->assertSame(
            'the fallback',
            $this->views->first(['pkg::published', 'pkg::fallback'])->render()
        );
    }

    public function test_first_prefers_the_earlier_candidate(): void
    {
        $this->write('package', 'published', 'the published one');
        $this->write('package', 'fallback', 'the fallback');

        $this->views->addNamespace('pkg', $this->dir('package'));

        $this->assertSame(
            'the published one',
            $this->views->first(['pkg::published', 'pkg::fallback'])->render()
        );
    }

    public function test_first_raises_when_none_exist(): void
    {
        $this->views->addNamespace('pkg', $this->dir('package'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('None of the views');

        $this->views->first(['pkg::a', 'pkg::b']);
    }

    // ─── file() ───────────────────────────────────────────

    public function test_a_template_can_be_addressed_by_absolute_path(): void
    {
        $path = $this->write('theme', 'loose', 'addressed by path');

        $this->assertSame('addressed by path', $this->views->file($path)->render());
    }

    public function test_a_template_addressed_by_path_receives_its_data(): void
    {
        $path = $this->write('theme', 'greets', 'Hello {{ $name }}');

        $this->assertSame('Hello Ada', $this->views->file($path, ['name' => 'Ada'])->render());
    }

    // ─── Conditional rendering ────────────────────────────

    public function test_render_when_renders_only_if_the_condition_holds(): void
    {
        $this->write('package', 'banner', 'shown');

        $this->views->addNamespace('pkg', $this->dir('package'));

        $this->assertSame('shown', $this->views->renderWhen(true, 'pkg::banner'));
        $this->assertSame('', $this->views->renderWhen(false, 'pkg::banner'));
    }

    public function test_render_unless_is_the_inverse(): void
    {
        $this->write('package', 'banner', 'shown');

        $this->views->addNamespace('pkg', $this->dir('package'));

        $this->assertSame('', $this->views->renderUnless(true, 'pkg::banner'));
        $this->assertSame('shown', $this->views->renderUnless(false, 'pkg::banner'));
    }

    // ─── Merge data ───────────────────────────────────────

    public function test_merge_data_supplies_defaults_the_data_overrides(): void
    {
        $this->write('package', 'greets', 'Hello {{ $name }}');

        $this->views->addNamespace('pkg', $this->dir('package'));

        $this->assertSame(
            'Hello Ada',
            $this->views->make('pkg::greets', ['name' => 'Ada'], ['name' => 'nobody'])->render()
        );

        $this->assertSame(
            'Hello nobody',
            $this->views->make('pkg::greets', [], ['name' => 'nobody'])->render()
        );
    }
}
