<?php

namespace Tests\Unit\View;

use Nitro\View\Compiler\Concerns\CompilesLoops;
use PHPUnit\Framework\TestCase;

class CompilesLoopsTest extends TestCase
{
    protected object $compiler;

    protected function setUp(): void
    {
        $this->compiler = new class {
            use CompilesLoops {
                compileForelse as public;
                compileEmpty as public;
                compileEndforelse as public;
                compileForeach as public;
                compileEndforeach as public;
            }
        };
    }

    public function test_forelse_then_empty_pairs_correctly(): void
    {
        $open = $this->compiler->compileForelse('($users as $user)');
        $empty = $this->compiler->compileEmpty('');
        $close = $this->compiler->compileEndforelse('');

        $this->assertStringContainsString('$__empty_1 = true', $open);
        $this->assertStringContainsString('if ($__empty_1)', $empty);
        $this->assertStringContainsString('endif', $close);
    }

    public function test_empty_with_args_is_value_check(): void
    {
        $php = $this->compiler->compileEmpty('($items)');
        $this->assertStringContainsString('if(empty($items))', $php);
    }

    public function test_bare_empty_outside_forelse_throws(): void
    {
        $this->expectException(\LogicException::class);
        $this->compiler->compileEmpty('');
    }

    public function test_counter_does_not_corrupt_after_bare_empty_attempt(): void
    {
        // First @forelse opens counter=1; bare @empty pairs and decrements.
        $this->compiler->compileForelse('($a as $x)');
        $this->compiler->compileEmpty('');
        $this->compiler->compileEndforelse('');

        // Stray @empty must throw rather than silently decrementing into negatives.
        try {
            $this->compiler->compileEmpty('');
            $this->fail('Stray @empty should have thrown.');
        } catch (\LogicException $e) {
            // expected
        }

        // Next @forelse should still pair cleanly.
        $open = $this->compiler->compileForelse('($b as $y)');
        $empty = $this->compiler->compileEmpty('');

        $this->assertStringContainsString('$__empty_1', $open);
        $this->assertStringContainsString('$__empty_1', $empty);
    }
}
