<?php

namespace Tests\Unit\View;

use Nitro\View\Compiler\BladeCompiler;
use Nitro\View\Compiler\ComponentTagCompiler;
use PHPUnit\Framework\TestCase;

/**
 * @csrf must compile to the canonical csrf_field() helper (which mints the
 * token via csrf_token() on demand) rather than reading $_SESSION["_csrf"]
 * raw — the raw read emitted an empty token whenever nothing had minted one,
 * silently breaking CSRF verification.
 */
class CompileCsrfTest extends TestCase
{
    private function compile(string $template): string
    {
        $tags = new ComponentTagCompiler();
        $blade = new BladeCompiler($tags);
        return $blade->compile($tags->compile($template));
    }

    public function test_csrf_directive_delegates_to_csrf_field(): void
    {
        $out = $this->compile('@csrf');

        $this->assertStringContainsString('csrf_field()', $out);
    }

    public function test_csrf_directive_does_not_read_session_superglobal(): void
    {
        $out = $this->compile('@csrf');

        $this->assertStringNotContainsString('$_SESSION', $out);
    }
}
