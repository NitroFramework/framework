<?php

namespace Tests\Unit\Livewire;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Http\Response;
use Nitro\Livewire\LivewireManager;
use PHPUnit\Framework\TestCase;

/**
 * The Livewire client runtime is owned and served by the framework package
 * (src/Livewire/dist/livewire.js via GET /livewire/livewire.js), the way
 * Livewire serves its own dist file — the app never ships a copy in public/.
 */
class LivewireAssetRouteTest extends TestCase
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
        /** @var LivewireManager $lw */
        $lw = Container::getInstance()->make('livewire');

        $this->assertFileExists($lw->scriptPath());
        $this->assertStringEndsWith(
            'src/Livewire/dist/livewire.js',
            str_replace('\\', '/', $lw->scriptPath())
        );
    }

    public function test_route_is_registered_and_not_behind_the_web_group(): void
    {
        $routes = Container::getInstance()->make('router')->getRoutes();

        $this->assertArrayHasKey('/livewire/livewire.js', $routes['GET']);
        // A public GET asset needs no CSRF/session, so it must NOT carry 'web'.
        $this->assertNotContains('web', $routes['GET']['/livewire/livewire.js']['middleware'] ?? []);
    }

    public function test_response_serves_javascript_with_immutable_cache_header(): void
    {
        /** @var LivewireManager $lw */
        $lw = Container::getInstance()->make('livewire');
        $res = $lw->scriptResponse();

        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('application/javascript', (string) $res->header('Content-Type'));
        $this->assertStringContainsString('immutable', (string) $res->header('Cache-Control'));
        $this->assertNotSame('', $res->getContent());
    }

    public function test_scripts_tag_points_at_the_framework_route_not_app_public(): void
    {
        /** @var LivewireManager $lw */
        $lw = Container::getInstance()->make('livewire');
        $html = $lw->scripts();

        $this->assertStringContainsString('/livewire/livewire.js?v=', $html);
        $this->assertStringNotContainsString('/js/livewire.js', $html);
    }
}
