<?php

namespace Tests\Unit\View;

use Nitro\View\Component\ComponentAttributeBag;
use PHPUnit\Framework\TestCase;

/**
 * The component attribute bag renders escaped HTML attributes and merges
 * defaults Laravel-style (class concatenates; everything else overrides).
 */
class ComponentAttributeBagTest extends TestCase
{
    public function test_renders_escaped_attributes(): void
    {
        $bag = new ComponentAttributeBag(['id' => 'x', 'data-q' => '"><script>']);

        $html = (string) $bag;
        $this->assertStringContainsString('id="x"', $html);
        $this->assertStringContainsString('data-q="&quot;&gt;&lt;script&gt;"', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_true_renders_bare_and_false_null_omitted(): void
    {
        $bag = new ComponentAttributeBag(['disabled' => true, 'hidden' => false, 'x' => null]);

        $this->assertSame('disabled', (string) $bag);
    }

    public function test_merge_concatenates_class_and_overrides_others(): void
    {
        $bag = (new ComponentAttributeBag(['class' => 'p-2', 'type' => 'submit']))
            ->merge(['class' => 'btn', 'type' => 'button']);

        $html = (string) $bag;
        $this->assertStringContainsString('class="btn p-2"', $html);
        $this->assertStringContainsString('type="submit"', $html); // attribute wins
    }

    public function test_only_and_except_filter_keys(): void
    {
        $bag = new ComponentAttributeBag(['id' => '1', 'class' => 'a', 'role' => 'b']);

        $this->assertSame(['id' => '1'], $bag->only(['id'])->all());
        $this->assertSame(['class' => 'a', 'role' => 'b'], $bag->except(['id'])->all());
    }
}
