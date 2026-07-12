<?php

namespace Tests\Unit\Fusion;

use Nitro\Fusion\Build\Builder;
use Nitro\Fusion\Build\FusionBuildException;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    private const COUNTER_PHP = '<?php
namespace App\Components;
class Counter
{
    public int $count = 0;
    public function increment(): void { $this->count++; }
    public function decrement(): void { $this->count--; }
}';

    private const COUNTER_VIEW = '<div><button fusion:click="decrement">-</button><span>{{ $count }}</span><button fusion:click="increment">+</button></div>';

    public function test_compiles_a_component_artifact(): void
    {
        $a = (new Builder())->compileComponent('Counter', self::COUNTER_PHP, self::COUNTER_VIEW);

        $this->assertSame('Counter', $a['name']);
        $this->assertStringContainsString('class Counter', $a['classJs']);
        $this->assertStringContainsString('$this.count++', $a['classJs']);
        $this->assertStringContainsString('${__esc(count)}', $a['renderJs']);
        $this->assertSame(['count'], $a['meta']['props']);
        $this->assertCount(2, $a['meta']['events']);
    }

    public function test_bundle_self_registers_the_component(): void
    {
        $b = new Builder();
        $bundle = $b->bundle([$b->compileComponent('Counter', self::COUNTER_PHP, self::COUNTER_VIEW)]);

        $this->assertStringContainsString('window.__fusion.registry["Counter"]', $bundle);
        $this->assertStringContainsString('component: Counter', $bundle);
        $this->assertStringContainsString('class Counter', $bundle);
        $this->assertStringContainsString('data-fusion-click="increment"', $bundle);
        $this->assertStringContainsString('const __render =', $bundle);
    }

    public function test_impure_component_fails_the_build(): void
    {
        $this->expectException(FusionBuildException::class);
        (new Builder())->compileComponent(
            'Bad',
            '<?php namespace App\Components; class Bad { public function x(): void { Post::create([]); } }',
            '<div></div>'
        );
    }
}
