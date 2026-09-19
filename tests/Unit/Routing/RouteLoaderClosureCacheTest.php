<?php

namespace Tests\Unit\Routing;

use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use Nitro\Http\Request;
use Nitro\Routing\Contracts\RouteType;
use Nitro\Routing\Route;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\RouteTypes;
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

    private function router(?RouteTypes $types = null): Router
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('App\\Controllers\\');
        return new Router($config, $types ?? new RouteTypes());
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

    /**
     * A route a feature layer contributed must not cost the application its
     * route cache.
     *
     * The layer this seam replaced used to register a closure that captured
     * the container, which made every route in the application uncacheable —
     * one such page was enough. A {@see RouteType} stores a name instead, so
     * the route is data and survives being written out.
     */
    public function test_a_contributed_route_type_is_cacheable(): void
    {
        $types = (new RouteTypes())->add($this->namedFeedType());

        $router = $this->router($types);
        $router->get('/users', 'UserController@index');
        $router->get('/basket', ['feed' => 'basket']);

        $skipped = $this->loader()->cache($router);

        $this->assertSame(0, $skipped, 'a route storing a name must not block caching');
        $this->assertFileExists($this->cacheFile());

        $data = require $this->cacheFile();

        $this->assertSame('feed', $data['routes']['GET']['/basket']['type']);
        $this->assertSame('basket', $data['routes']['GET']['/basket']['handler']);

        /*
         * And the half that matters on a warm boot: read back, matched, and
         * handed to the layer — the router rebuilding a route whose kind it
         * still does not know.
         */
        $warm = $this->router($types);
        $warm->loadCachedRoutes($data);

        $route = $warm->findMatchingRoute(new Request('GET', '/basket'));

        $this->assertNotNull($route, 'a cached contributed route must still match');
        $this->assertSame('feed', $route->getType());
        $this->assertSame('basket', $route->getHandler());
    }

    /** A route type that stores a name, the shape every cacheable one has. */
    private function namedFeedType(): RouteType
    {
        return new class implements RouteType {
            public function name(): string
            {
                return 'feed';
            }

            public function parse(mixed $handler): mixed
            {
                return is_array($handler) && isset($handler['feed'])
                    ? (string) $handler['feed']
                    : null;
            }

            public function dispatch(Route $route, Request $request): mixed
            {
                return 'feed: ' . $route->getHandler();
            }
        };
    }
}
