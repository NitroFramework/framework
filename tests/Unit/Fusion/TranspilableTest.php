<?php

namespace Tests\Unit\Fusion;

use Nitro\Fusion\Concerns\Transpilable;
use PHPUnit\Framework\TestCase;

class FusionStateComponent
{
    use Transpilable;

    public int $count = 3;
    public array $items = ['a', 'b'];
    protected string $secret = 'hidden';   // server-only

    public function secret(): string
    {
        return $this->secret;
    }
}

/**
 * The Transpilable trait carries a #[Client] component's server-side state
 * bridge: serialize public props for SSR hydration, and rebuild them from a
 * client-sent state — public props only, never protected/private.
 */
class TranspilableTest extends TestCase
{
    public function test_fusion_state_serializes_public_props_only(): void
    {
        $state = (new FusionStateComponent())->fusionState();

        $this->assertSame(['count' => 3, 'items' => ['a', 'b']], $state);
        $this->assertArrayNotHasKey('secret', $state);
    }

    public function test_fusion_fill_applies_public_props(): void
    {
        $c = new FusionStateComponent();
        $c->fusionFill(['count' => 9, 'items' => ['x']]);

        $this->assertSame(9, $c->count);
        $this->assertSame(['x'], $c->items);
    }

    public function test_fusion_fill_ignores_protected_props(): void
    {
        $c = new FusionStateComponent();
        $c->fusionFill(['count' => 1, 'secret' => 'hacked']);

        $this->assertSame(1, $c->count);
        $this->assertSame('hidden', $c->secret(), 'a client cannot write a protected property');
    }
}
