<?php

namespace Tests\Unit\View;

use Nitro\View\Compiler\BladeCompiler;
use PHPUnit\Framework\TestCase;

/**
 * What is inside @php is PHP, not a template.
 *
 * Compiling it is how a comment that mentions a directive silently becomes one.
 * "an inline @if" in a code comment compiled to "<?php if: ?>", which closed
 * the PHP block early and left every assignment after it undefined — and the
 * error then names the variable, nowhere near the comment that caused it.
 *
 * The same shape as writing a closing PHP tag inside a comment, and just as
 * hard to find.
 */
class RawPhpBlockTest extends TestCase
{
    private function compile(string $template): string
    {
        return (new BladeCompiler(new \Nitro\View\Compiler\ComponentTagCompiler()))->compile($template);
    }

    public function test_a_directive_named_in_a_comment_is_left_alone(): void
    {
        $compiled = $this->compile(<<<'BLADE'
        @php
            // Composed here rather than with an inline @if, because Blade
            // ignores a directive whose @ is preceded by a letter.
            $when = 'now';
        @endphp
        {{ $when }}
        BLADE);

        $this->assertStringContainsString('$when = \'now\';', $compiled);
        $this->assertStringNotContainsString('if:', $compiled);
    }

    public function test_an_echo_inside_a_php_block_is_left_alone(): void
    {
        $compiled = $this->compile(<<<'BLADE'
        @php
            $label = '{{ not an echo }}';
        @endphp
        BLADE);

        $this->assertStringContainsString("'{{ not an echo }}'", $compiled);
    }

    public function test_a_component_tag_in_a_comment_is_not_compiled(): void
    {
        // The trap that cost an afternoon in the Laravel original: a docblock
        // in an @props block naming <x-busy> compiled a real component call,
        // and the error came from the component, nowhere near the comment.
        $compiled = $this->compile(<<<'BLADE'
        @php
            /** Passed through to <x-busy>, which shows the overlay. */
            $busy = true;
        @endphp
        BLADE);

        $this->assertStringNotContainsString('render(', $compiled);
        $this->assertStringContainsString('$busy = true;', $compiled);
    }

    public function test_the_block_still_becomes_real_php(): void
    {
        $compiled = $this->compile("@php\n \$x = 1;\n@endphp");

        $this->assertStringContainsString('<?php', $compiled);
        $this->assertStringContainsString('$x = 1;', $compiled);
        $this->assertStringContainsString('?>', $compiled);
    }

    public function test_two_blocks_are_both_kept(): void
    {
        $compiled = $this->compile("@php \$a = 1; @endphp\n<p>x</p>\n@php \$b = 2; @endphp");

        $this->assertStringContainsString('$a = 1;', $compiled);
        $this->assertStringContainsString('$b = 2;', $compiled);
    }

    public function test_directives_outside_the_block_still_compile(): void
    {
        $compiled = $this->compile(<<<'BLADE'
        @php $show = true; @endphp
        @if ($show)
            <p>Visible</p>
        @endif
        BLADE);

        $this->assertStringContainsString('if($show):', $compiled);
        $this->assertStringContainsString('$show = true;', $compiled);
    }

    public function test_the_one_line_form_still_works(): void
    {
        // @php($x = 1) is the directive form and is not a block.
        $compiled = $this->compile('@php($count = 3)');

        $this->assertStringContainsString('$count = 3', $compiled);
    }
}
