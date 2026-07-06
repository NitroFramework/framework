<?php

namespace Tests\Unit\Livewire;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Http\Request;
use Nitro\Livewire\Attributes\RenderRegion;
use Nitro\Livewire\Component;
use Nitro\Livewire\LivewireManager;
use PHPUnit\Framework\TestCase;

/**
 * #[RenderRegion('name')]: an action re-renders only a named wire:region block
 * instead of the whole component. It is a scoped re-render — the client patches
 * just that region. (Distinct from islands, which are isolated.)
 */
class LivewireRenderAttributesTest extends TestCase
{
    private static bool $bootstrapped = false;

    public static function setUpBeforeClass(): void
    {
        if (! self::$bootstrapped) {
            require_once __DIR__ . '/../../../vendor/autoload.php';
            Application::create(dirname(__DIR__, 3))->bootstrap();
            self::$bootstrapped = true;
        }
    }

    protected function setUp(): void
    {
        Container::getInstance()->instance(Request::class, new Request('GET', '/'));
    }

    private function lw(): LivewireManager
    {
        return Container::getInstance()->make('livewire');
    }

    /** Run a single action against a fresh probe and return its effects. */
    private function fire(string $method): array
    {
        $this->lw()->component('rr-probe', RenderRegionProbe::class);
        $probe = $this->lw()->makeComponent('rr-probe');
        $probe->setContext('x', 'rr-probe');
        $snapshot = $this->lw()->snapshot($probe);

        $result = $this->lw()->update(['components' => [[
            'snapshot' => $snapshot,
            'calls'    => [['method' => $method, 'params' => []]],
        ]]]);

        return $result['components'][0]['effects'];
    }

    public function test_render_region_attribute_scopes_to_the_region(): void
    {
        $effects = $this->fire('bump');

        $this->assertArrayHasKey('region', $effects);
        $this->assertArrayNotHasKey('html', $effects);
        $this->assertSame('box', $effects['region']['name']);
        $this->assertStringContainsString('n: 1', $effects['region']['html']);
    }

    public function test_explicit_render_region_call_scopes_to_the_region(): void
    {
        $effects = $this->fire('bumpExplicit');

        $this->assertArrayHasKey('region', $effects);
        $this->assertSame('box', $effects['region']['name']);
    }

    public function test_action_without_attribute_renders_full_html(): void
    {
        $effects = $this->fire('noop');

        $this->assertArrayHasKey('html', $effects);
        $this->assertArrayNotHasKey('region', $effects);
    }
}

class RenderRegionProbe extends Component
{
    public int $n = 0;

    #[RenderRegion('box')]
    public function bump(): void
    {
        $this->n++;
    }

    public function bumpExplicit(): void
    {
        $this->n++;
        $this->renderRegion('box');
    }

    public function noop(): void
    {
        //
    }

    public function render(): string
    {
        return '<div><h1>Head</h1><div wire:region="box">n: ' . $this->n . '</div></div>';
    }
}
