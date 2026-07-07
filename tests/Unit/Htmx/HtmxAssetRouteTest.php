<?php

namespace Tests\Unit\Htmx;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Htmx\Support\HtmxAssets;
use Nitro\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * The HTMX component runtime is owned and served by the framework package
 * (src/Htmx/dist/hx-component.js via GET /nitro/hx-component.js), the same way
 * the Livewire layer serves its runtime — the app never ships a copy in public/.
 */
class HtmxAssetRouteTest extends TestCase
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

    public function test_runtime_is_bundled_inside_the_framework_package(): void
    {
        $this->assertFileExists(HtmxAssets::scriptPath());
        $this->assertStringEndsWith(
            'src/Htmx/dist/hx-component.js',
            str_replace('\\', '/', HtmxAssets::scriptPath())
        );
    }

    public function test_route_is_registered_and_not_behind_the_web_group(): void
    {
        $routes = Container::getInstance()->make('router')->getRoutes();

        $this->assertArrayHasKey('/nitro/hx-component.js', $routes['GET']);
        $this->assertNotContains('web', $routes['GET']['/nitro/hx-component.js']['middleware'] ?? []);
    }

    public function test_response_serves_javascript_with_immutable_cache_header(): void
    {
        $res = (new HtmxAssets())->scriptResponse();

        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('application/javascript', (string) $res->header('Content-Type'));
        $this->assertStringContainsString('immutable', (string) $res->header('Cache-Control'));
        $this->assertNotSame('', $res->getContent());
    }

    public function test_script_tag_points_at_the_framework_route_not_app_public(): void
    {
        $tag = (new HtmxAssets())->scriptTag();

        $this->assertStringContainsString('/nitro/hx-component.js?v=', $tag);
        $this->assertStringNotContainsString('/js/hx-component.js', $tag);
    }
}
