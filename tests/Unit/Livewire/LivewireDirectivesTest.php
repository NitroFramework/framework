<?php

namespace Tests\Unit\Livewire;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Livewire\Component;
use Nitro\Livewire\Js;
use Nitro\Livewire\LivewireManager;
use Nitro\View\Contracts\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Blade @-directives (@js/@this/@entangle/@script/@assets/@persist)
 * and the self-contained region mechanism (@region + wire:region extraction and
 * the scoped region commit effect).
 */
class LivewireDirectivesTest extends TestCase
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

    private function compile(string $template): string
    {
        return Container::getInstance()->make(TemplateCompiler::class)->compile($template);
    }

    private function lw(): LivewireManager
    {
        return Container::getInstance()->make('livewire');
    }

    // ─── @js ────────────────────────────────────────────────────────────────

    public function test_js_encodes_a_value_as_a_safe_js_literal(): void
    {
        $this->assertSame('{"a":1,"b":"x"}', Js::from(['a' => 1, 'b' => 'x']));
        // < > & ' " are escaped so the literal is <script>-safe.
        $this->assertStringNotContainsString('<', Js::from('<b>'));
    }

    public function test_blade_compiles_js_this_and_entangle(): void
    {
        $out = $this->compile("@js(['a'=>1]) @this @entangle('search')");
        $this->assertStringContainsString('\\Nitro\\Livewire\\Js::from([\'a\'=>1])', $out);
        $this->assertStringContainsString('$wire', $out);
        $this->assertStringContainsString("\$wire.entangle('search')", $out);
    }

    // ─── @script / @assets / @persist ───────────────────────────────────────

    public function test_blade_compiles_script_block(): void
    {
        $out = $this->compile("@script\nalert(1)\n@endscript");
        $this->assertStringContainsString('<script type="text/nitro-script">', $out);
        $this->assertStringContainsString('</script>', $out);
    }

    public function test_blade_compiles_assets_block(): void
    {
        $out = $this->compile("@assets\n<b>x</b>\n@endassets");
        $this->assertStringContainsString('<template wire:assets>', $out);
        $this->assertStringContainsString('</template>', $out);
    }

    public function test_blade_compiles_persist_block(): void
    {
        $out = $this->compile("@persist('player')\nX\n@endpersist");
        $this->assertStringContainsString('wire:persist="', $out);
        $this->assertStringContainsString('player', $out);
    }

    // ─── Regions ────────────────────────────────────────────────────────────

    public function test_blade_compiles_region_block(): void
    {
        $out = $this->compile("@region('posts')\nX\n@endregion");
        $this->assertStringContainsString('wire:region="', $out);
        $this->assertStringContainsString('posts', $out);
    }

    public function test_region_commit_returns_only_the_region_html(): void
    {
        $this->lw()->component('region-probe', RegionProbe::class);

        $probe = new RegionProbe();
        $probe->setContext('is1', 'region-probe');
        $snapshot = $this->lw()->snapshot($probe);

        $result = $this->lw()->update(['components' => [[
            'snapshot' => $snapshot,
            'calls'    => [['method' => 'bump', 'params' => []]],
            'region'   => 'counter',
        ]]]);

        $effects = $result['components'][0]['effects'];
        $this->assertArrayHasKey('region', $effects);
        $this->assertArrayNotHasKey('html', $effects); // full html suppressed
        $this->assertSame('counter', $effects['region']['name']);
        $this->assertStringContainsString('Count: 1', $effects['region']['html']);
        $this->assertStringStartsWith('<div wire:region="counter"', $effects['region']['html']);
    }

    public function test_commit_without_region_returns_full_html(): void
    {
        $this->lw()->component('region-probe', RegionProbe::class);

        $probe = new RegionProbe();
        $probe->setContext('is2', 'region-probe');
        $snapshot = $this->lw()->snapshot($probe);

        $result = $this->lw()->update(['components' => [[
            'snapshot' => $snapshot,
            'calls'    => [['method' => 'bump', 'params' => []]],
        ]]]);

        $effects = $result['components'][0]['effects'];
        $this->assertArrayHasKey('html', $effects);
        $this->assertArrayNotHasKey('region', $effects);
    }
}

class RegionProbe extends Component
{
    public int $count = 0;

    public function bump(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<div><h1>Head</h1><div wire:region="counter">Count: ' . $this->count . '</div></div>';
    }
}
