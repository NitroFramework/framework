<?php

namespace Tests\Unit\View;

use Nitro\View\Compiler\Concerns\CompilesInjections;
use PHPUnit\Framework\TestCase;

/**
 * @inject directive interpolates its arguments into compiled PHP, so it must
 * validate that the variable name and class name are safe identifiers.
 */
class CompilesInjectionsTest extends TestCase
{
    protected object $compiler;

    protected function setUp(): void
    {
        $this->compiler = new class {
            use CompilesInjections {
                compileInject as public;
            }
        };
    }

    public function test_valid_inject_compiles(): void
    {
        $php = $this->compiler->compileInject("('logger', 'App\\Services\\Logger')");
        $this->assertStringContainsString('$logger', $php);
        $this->assertStringContainsString("'App\\Services\\Logger'", $php);
        $this->assertStringStartsWith('<?php ', $php);
    }

    public function test_unqualified_class_name_compiles(): void
    {
        $php = $this->compiler->compileInject("('logger', 'Logger')");
        $this->assertStringContainsString("'Logger'", $php);
    }

    public function test_invalid_variable_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->compiler->compileInject("('x; system(\\'rm -rf /\\'); //', 'App\\Logger')");
    }

    public function test_invalid_class_name_throws_on_quote_injection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->compiler->compileInject("('logger', \"'; system('id'); //\")");
    }

    public function test_invalid_class_name_throws_on_special_chars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->compiler->compileInject("('logger', 'App-Services')");
    }

    public function test_empty_args_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->compiler->compileInject('()');
    }
}
