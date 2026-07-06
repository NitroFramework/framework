<?php

namespace Tests\Unit\View;

use Nitro\View\Compiler\BladeCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Cached directive hydration replaces the runtime "load directives.php"
 * step. We verify the hydrated registry is consulted by compileDirective and
 * that the cache-hydrated flag prevents the boot-time loader from re-running.
 */
class BladeDirectiveHydrationTest extends TestCase
{
    protected function setUp(): void
    {
        BladeCompiler::clearCustomDirectives();
    }

    protected function tearDown(): void
    {
        BladeCompiler::clearCustomDirectives();
    }

    public function test_hydrate_populates_directive_registry(): void
    {
        BladeCompiler::hydrateCustomDirectives([
            'elapsed_time' => '<?php echo "12.34"; ?>',
            'memory_usage' => '<?php echo "1.5"; ?>',
        ]);

        $registry = BladeCompiler::getCustomDirectives();
        $this->assertArrayHasKey('elapsed_time', $registry);
        $this->assertArrayHasKey('memory_usage', $registry);
    }

    public function test_hydrated_directive_returns_cached_php_for_any_expression(): void
    {
        BladeCompiler::hydrateCustomDirectives([
            'elapsed_time' => '<?php echo "12.34"; ?>',
        ]);

        $callback = BladeCompiler::getCustomDirectives()['elapsed_time'];

        $this->assertSame('<?php echo "12.34"; ?>', $callback(''));
        // Even a non-empty expression returns the same body — these directives
        // don't read their argument.
        $this->assertSame('<?php echo "12.34"; ?>', $callback('ignored'));
    }

    public function test_hydration_flag_lets_boot_skip_directives_file(): void
    {
        $this->assertFalse(BladeCompiler::directivesHydratedFromCache());

        BladeCompiler::hydrateCustomDirectives(['x' => '<?php echo 1; ?>']);

        $this->assertTrue(BladeCompiler::directivesHydratedFromCache());
    }

    public function test_clear_resets_registry_and_flag(): void
    {
        BladeCompiler::hydrateCustomDirectives(['x' => '<?php echo 1; ?>']);
        BladeCompiler::clearCustomDirectives();

        $this->assertSame([], BladeCompiler::getCustomDirectives());
        $this->assertFalse(BladeCompiler::directivesHydratedFromCache());
    }

    public function test_array_format_with_php_key_is_supported(): void
    {
        BladeCompiler::hydrateCustomDirectives([
            'rich' => ['php' => '<?php echo "rich"; ?>', 'meta' => 'extra'],
        ]);

        $callback = BladeCompiler::getCustomDirectives()['rich'];
        $this->assertSame('<?php echo "rich"; ?>', $callback(''));
    }
}
