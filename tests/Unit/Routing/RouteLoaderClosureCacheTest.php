<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Route caching is all-or-nothing: a Closure handler can't be var_export'd
 * (it emits \Closure::__set_state, which fatals on require). RouteLoader::cache
 * must refuse to write a corrupt cache when any closure route exists, and write
 * a clean, requirable cache when every route is a controller/string handler.
 */
class RouteLoaderClosureCacheTest extends TestCase
{
    private string $cacheRoot;

    protected function setUp(): void
    {
        $this->cacheRoot = sys_get_temp_dir() . '/nitro-routecache-' . bin2hex(random_bytes(4));
        mkdir($this->cacheRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->cacheRoot . '/routes/routes.php';
        @unlink($file);
        @rmdir($this->cacheRoot . '/routes');
        @rmdir($this->cacheRoot);
    }

    private function paths(): PathRegistry
    {
        return new class($this->cacheRoot) extends PathRegistry {
            public function __construct(private string $root) {}
            public function base(string $path = ''): string { return sys_get_temp_dir() . '/nitro-noroutes/' . $path; }
            public function cache(string $path = ''): string { return $this->root . '/' . $path; }
            public function config(string $path = ''): string { return sys_get_temp_dir() . '/nitro-noconfig/' . $path; }
        };
    }

    private function loader(): RouteLoader
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(false); // app.debug=false
        return new RouteLoader($this->paths(), $config);
    }

    private function router(): Router
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('App\\Controllers\\');
        return new Router($config);
    }

    private function cacheFile(): string
    {
        return $this->cacheRoot . '/routes/routes.php';
    }

    public function test_closure_route_blocks_caching_and_writes_no_file(): void
    {
        $router = $this->router();
        $router->get('/x', fn() => 'hi');

        $skipped = $this->loader()->cache($router);

        $this->assertGreaterThan(0, $skipped, 'closure routes must block route caching');
        $this->assertFileDoesNotExist($this->cacheFile());
    }

    public function test_controller_routes_produce_a_clean_requirable_cache(): void
    {
        $router = $this->router();
        $router->get('/users', 'UserController@index');

        $skipped = $this->loader()->cache($router);

        $this->assertSame(0, $skipped);
        $this->assertFileExists($this->cacheFile());

        // Must require without fatal (no \Closure::__set_state corruption).
        $data = require $this->cacheFile();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('static_routes', $data);
    }
}
