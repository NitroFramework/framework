<?php

namespace Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use Nitro\View\Compiler\BladeCompiler;
use Nitro\View\Compiler\ComponentTagCompiler;
use Nitro\View\Support\ViewManifest;

/**
 * Regression tests covering the view-perf fix batch:
 *   - {{ }} compiles to inline \nitro_e() (not $this->e())
 *   - @include with explicit data skips get_defined_vars()
 *   - @include without data still emits get_defined_vars() so parent
 *     scope is preserved
 *   - one regex pass handles both echo forms
 *   - ViewManifest round-trips
 *   - the global nitro_e() helper itself works
 */
class ViewPerfFixesTest extends TestCase
{
    private function compile(string $blade): string
    {
        $tagCompiler = new ComponentTagCompiler();
        return (new BladeCompiler($tagCompiler))->compile($blade);
    }

    // ── echo inlining ────────────────────────────────────

    public function test_escaped_echo_uses_global_function(): void
    {
        $out = $this->compile('{{ $name }}');
        $this->assertStringContainsString('\\nitro_e($name)', $out);
        $this->assertStringNotContainsString('$this->e(', $out);
    }

    public function test_raw_echo_unchanged(): void
    {
        $out = $this->compile('{!! $html !!}');
        $this->assertStringContainsString('<?php echo $html; ?>', $out);
        $this->assertStringNotContainsString('nitro_e', $out);
    }

    public function test_escaped_echo_escape_syntax(): void
    {
        // @{{ … }} should produce literal {{ … }} in output, NOT echo it.
        $out = $this->compile('@{{ $name }}');
        $this->assertStringContainsString('{{ $name }}', $out);
        $this->assertStringNotContainsString('nitro_e', $out);
    }

    public function test_mixed_echoes_in_one_pass(): void
    {
        $out = $this->compile('{{ $a }} and {!! $b !!} and {{ $c }}');
        $this->assertStringContainsString('\\nitro_e($a)', $out);
        $this->assertStringContainsString('echo $b;', $out);
        $this->assertStringContainsString('\\nitro_e($c)', $out);
    }

    // ── nitro_e() global ─────────────────────────────────

    public function test_nitro_e_escapes_string(): void
    {
        $this->assertSame('&lt;b&gt;', \nitro_e('<b>'));
    }

    public function test_nitro_e_passes_htmlable_untouched(): void
    {
        $html = new \Nitro\View\Support\HtmlString('<strong>kept</strong>');
        $this->assertSame('<strong>kept</strong>', \nitro_e($html));
    }

    public function test_nitro_e_null_becomes_empty(): void
    {
        $this->assertSame('', \nitro_e(null));
    }

    public function test_nitro_e_handles_int_and_bool(): void
    {
        $this->assertSame('42', \nitro_e(42));
        $this->assertSame('1',  \nitro_e(true));
        $this->assertSame('',   \nitro_e(false));
    }

    // ── @include two-path compile ────────────────────────

    public function test_include_with_explicit_data_skips_get_defined_vars(): void
    {
        $out = $this->compile("@include('partials.header', ['title' => \$title])");
        $this->assertStringContainsString('renderPartial(', $out);
        $this->assertStringNotContainsString('get_defined_vars()', $out);
    }

    public function test_include_without_data_still_uses_get_defined_vars(): void
    {
        $out = $this->compile("@include('partials.header')");
        $this->assertStringContainsString('renderInclude(', $out);
        $this->assertStringContainsString('get_defined_vars()', $out);
    }

    public function test_include_with_comma_inside_string_is_not_misread_as_arg(): void
    {
        // The view name contains a comma inside its string literal — the
        // arity detector must NOT treat that comma as an argument separator.
        $out = $this->compile("@include('foo,bar')");
        $this->assertStringContainsString('renderInclude(', $out);
        $this->assertStringContainsString('get_defined_vars()', $out);
    }

    public function test_include_with_nested_array_data(): void
    {
        // Commas inside the nested array shouldn't trip the depth tracker.
        $out = $this->compile("@include('v', ['a' => 1, 'b' => [1, 2, 3]])");
        $this->assertStringContainsString('renderPartial(', $out);
        $this->assertStringNotContainsString('get_defined_vars()', $out);
    }

    // ── ViewManifest round-trip ──────────────────────────

    public function test_view_manifest_set_and_isStream(): void
    {
        ViewManifest::set([
            'pages.home'    => ['stream' => true],
            'pages.contact' => ['stream' => false],
        ]);

        $this->assertTrue(ViewManifest::isStream('pages.home'));
        $this->assertFalse(ViewManifest::isStream('pages.contact'));
        $this->assertNull(ViewManifest::isStream('pages.unknown'),
            'Unknown views should return null so the renderer falls back to a live probe.');

        ViewManifest::set(null);
    }

    public function test_view_manifest_flags_returns_full_entry(): void
    {
        ViewManifest::set([
            'pages.home' => ['stream' => true, 'has_extends' => false],
        ]);

        $flags = ViewManifest::flags('pages.home');
        $this->assertIsArray($flags);
        $this->assertTrue($flags['stream']);
        $this->assertFalse($flags['has_extends']);

        ViewManifest::set(null);
    }
}
