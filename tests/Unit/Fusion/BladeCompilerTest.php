<?php

namespace Tests\Unit\Fusion;

use Nitro\Fusion\Compiler\BladeCompiler;
use PHPUnit\Framework\TestCase;

/**
 * The reactive-Blade compiler turns a component's Blade view into a client render
 * function + the fusion: bindings. Nitro's Blade still renders SSR; this is the
 * browser counterpart.
 */
class BladeCompilerTest extends TestCase
{
    private function compile(string $tpl, array $props = []): \Nitro\Fusion\Compiler\CompiledTemplate
    {
        return (new BladeCompiler())->compile($tpl, $props);
    }

    public function test_interpolation_escapes_and_destructures_props(): void
    {
        $r = $this->compile('<span>{{ $count }}</span>', ['count']);

        $this->assertStringContainsString('const { count } = c;', $r->js);
        $this->assertStringContainsString('${__esc(count)}', $r->js);
        $this->assertStringContainsString('(c) => {', $r->js);
    }

    public function test_expression_is_transpiled(): void
    {
        $r = $this->compile('<b>{{ $count * 2 }}</b>', ['count']);
        $this->assertStringContainsString('${__esc(count * 2)}', $r->js);
    }

    public function test_method_call_expression(): void
    {
        $r = $this->compile('<i>{{ $this->double() }}</i>', ['count']);
        $this->assertStringContainsString('${__esc($this.double())}', $r->js);
    }

    public function test_raw_interpolation_is_not_escaped(): void
    {
        $r = $this->compile('<div>{!! $html !!}</div>', ['html']);
        $this->assertStringContainsString('${html}', $r->js);
        $this->assertStringNotContainsString('__esc(html)', $r->js);
    }

    public function test_fusion_click_becomes_a_delegated_event(): void
    {
        $r = $this->compile('<button fusion:click="increment">+</button>');

        $this->assertSame([['event' => 'click', 'method' => 'increment']], $r->events);
        $this->assertStringContainsString('data-fusion-click="increment"', $r->js);
        $this->assertStringNotContainsString('fusion:click', $r->js);
    }

    public function test_fusion_model_is_recorded(): void
    {
        $r = $this->compile('<input fusion:model="title">', ['title']);

        $this->assertSame(['title'], $r->models);
        $this->assertStringContainsString('data-fusion-model="title"', $r->js);
    }

    public function test_full_counter_template(): void
    {
        $r = $this->compile(
            '<div><button fusion:click="decrement">-</button><span>{{ $count }}</span><button fusion:click="increment">+</button></div>',
            ['count']
        );

        $this->assertCount(2, $r->events);
        $this->assertStringContainsString('${__esc(count)}', $r->js);
        $this->assertStringContainsString('data-fusion-click="decrement"', $r->js);
        $this->assertStringContainsString('data-fusion-click="increment"', $r->js);
    }
}
