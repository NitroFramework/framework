<?php

namespace Tests\Unit\Fusion;

use Nitro\Container\Container;
use Nitro\Fusion\Runtime\FusionServer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The #[Server] boundary: rebuild the component from client state, run ONLY a
 * declared #[Server] method (never a pure or internal one), return the patch.
 */
class FusionServerTest extends TestCase
{
    private function server(): FusionServer
    {
        return new FusionServer(Container::getInstance(), 'Tests\\Unit\\Fusion\\Fixtures\\');
    }

    public function test_runs_a_server_method_and_returns_the_state_patch(): void
    {
        $result = $this->server()->handle([
            'component' => 'DemoCounter',
            'method'    => 'persist',
            'state'     => ['count' => 7],
        ]);

        $this->assertSame(7, $result['state']['count']);
        $this->assertSame(7, $result['state']['persisted']); // persist() copied count → persisted
    }

    public function test_rejects_a_pure_ui_method(): void
    {
        // increment() is a Pure-UI method — it runs on the CLIENT, never via the endpoint.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a #[Server] method');
        $this->server()->handle(['component' => 'DemoCounter', 'method' => 'increment', 'state' => []]);
    }

    public function test_rejects_an_internal_method(): void
    {
        $this->expectException(RuntimeException::class);
        $this->server()->handle(['component' => 'DemoCounter', 'method' => 'fusionState', 'state' => []]);
    }

    public function test_fill_ignores_protected_state_from_the_client(): void
    {
        $result = $this->server()->handle([
            'component' => 'DemoCounter',
            'method'    => 'persist',
            'state'     => ['count' => 3, 'secret' => 'hacked'], // 'secret' is protected → ignored
        ]);

        $this->assertSame(3, $result['state']['count']);
        $this->assertArrayNotHasKey('secret', $result['state']); // never serialized/writable
    }

    public function test_rejects_an_unknown_component(): void
    {
        $this->expectException(RuntimeException::class);
        $this->server()->handle(['component' => 'Nope', 'method' => 'persist', 'state' => []]);
    }
}
