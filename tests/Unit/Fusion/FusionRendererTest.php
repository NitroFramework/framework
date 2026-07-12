<?php

namespace Tests\Unit\Fusion;

use Nitro\Container\Container;
use Nitro\Fusion\Runtime\FusionRenderer;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fusion\Fixtures\DemoCounter;

/**
 * Server-side render for first paint: the component is wrapped in a
 * [data-fusion-root] with its serialized state and the same data-fusion-*
 * attributes the client render produces — so hydration doesn't flash.
 */
class FusionRendererTest extends TestCase
{
    public function test_ssr_wraps_root_with_state_and_data_attributes(): void
    {
        $html = (new FusionRenderer(Container::getInstance()))->render(DemoCounter::class, ['count' => 5]);

        $this->assertStringContainsString('data-fusion-root', $html);
        $this->assertStringContainsString('data-fusion-name="DemoCounter"', $html);
        $this->assertStringContainsString('{"count":5', $html);              // serialized hydration state
        $this->assertStringContainsString('data-fusion-click="increment"', $html);
        $this->assertStringContainsString('<span>5</span>', $html);          // {{ $count }} rendered
        $this->assertStringNotContainsString('fusion:click', $html);         // transformed to data-fusion-*
    }
}
