<?php

namespace Tests\Unit\Routing;

use Nitro\Container\Container;
use Nitro\Container\ContainerClassResolver;
use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use Nitro\Http\Request;
use Nitro\Routing\Contracts\RouterInterface;
use Nitro\Routing\Contracts\RouteType;
use Nitro\Routing\Route;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Route caching is all-or-nothing, but a closure is no longer what stops it.
 *
 * A Closure cannot be var_export'd — it emits \Closure::__set_state, which
 * fatals on require — so each one is serialized on the way in and restored on
 * the way out. What still blocks the cache is a handler that will not
 * serialize at all, and those routes are named rather than counted, since a
 * count says there is a problem and a list says where.
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

    /**
     * A container standing in for the application's, so a handler that
     * captured a service can be handed the live one when it comes back.
     */
    private function container(?Router $router = null): Container
    {
        $container = new Container();
        $container->instance(ContainerInterface::class, $container);

        if ($router !== null) {
            $container->instance(RouterInterface::class, $router);
        }

        return $container;
    }

    private function loader(?Container $container = null): RouteLoader
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(false); // app.debug=false

        return new RouteLoader(
            $this->paths(),
            $config,
            new ContainerClassResolver($container ?? $this->container())
        );
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

    public function test_a_closure_route_is_serialized_into_the_cache(): void
    {
        $router = $this->router();
        $router->get('/x', fn() => 'hi');

        $skipped = $this->loader()->cache($router);

        $this->assertSame(0, $skipped, 'a serializable closure must not block caching');
        $this->assertFileExists($this->cacheFile());

        // Requirable without fatal: no \Closure::__set_state in the file.
        $data = require $this->cacheFile();

        $this->assertIsArray($data);
    }

    /**
     * The half that matters: a closure read back out still runs.
     *
     * Writing one is only useful if what comes back behaves like the closure
     * that went in — a cache that loads but hands back a broken handler is
     * worse than no cache, because it fails at request time.
     */
    public function test_a_cached_closure_still_runs_when_loaded_back(): void
    {
        $router = $this->router();
        $router->get('/greet', fn() => 'hello from the cache');

        $this->assertSame(0, $this->loader()->cache($router));

        $warm = $this->router();
        $this->loader()->load($warm);

        $route = $warm->findMatchingRoute(new Request('GET', '/greet'));

        $this->assertNotNull($route, 'a cached closure route must still match');

        $handler = $route->getHandler();

        $this->assertInstanceOf(\Closure::class, $handler, 'the handler must come back a closure');
        $this->assertSame('hello from the cache', $handler());
    }

    /**
     * A handler holding live machinery — an open connection, a generator part
     * way through — cannot be written to a file at all. The cache is refused
     * rather than written partial, and the route is named.
     */
    public function test_an_unserializable_handler_blocks_the_cache_and_is_named(): void
    {
        $stream = (static function () { yield 'row'; })();

        $router = $this->router();
        $router->get('/ok', fn() => 'fine');
        $router->get('/streaming', fn() => $stream);

        $loader = $this->loader();
        $skipped = $loader->cache($router);

        $this->assertGreaterThan(0, $skipped);
        $this->assertFileDoesNotExist(
            $this->cacheFile(),
            'a partial cache would answer 404 for whatever it dropped'
        );

        $this->assertContains(
            'GET /streaming',
            $loader->uncacheableRoutes(),
            'the route that blocked the cache must be named'
        );
        $this->assertNotContains('GET /ok', $loader->uncacheableRoutes());
    }

    /**
     * Restoring a handler is the expensive half, so it happens per match.
     *
     * Rebuilding a cached closure makes PHP compile its stored source, which
     * costs far more than the table lookup the cache exists to save. Doing
     * that for every route at load made a cached boot slower than no cache at
     * all — 21 ms against 0.8 ms on a hundred routes. A request needs one
     * handler, so it pays for one.
     */
    public function test_handlers_are_restored_only_when_their_route_is_matched(): void
    {
        $router = $this->router();
        $router->get('/a', fn() => 'a');
        $router->get('/b', fn() => 'b');

        $this->assertSame(0, $this->loader()->cache($router));

        $warm = $this->router();
        $this->loader()->load($warm);

        foreach ($warm->getRoutes() as $method => $methodRoutes) {
            foreach ($methodRoutes as $path => $handler) {
                $this->assertNotInstanceOf(
                    \Closure::class,
                    $handler['handler'] ?? null,
                    "{$method} {$path} was rebuilt at load, before anything asked for it"
                );
            }
        }

        // And the one that is asked for comes back whole.
        $route = $warm->findMatchingRoute(new Request('GET', '/a'));

        $this->assertNotNull($route);

        $handler = $route->getHandler();

        $this->assertInstanceOf(\Closure::class, $handler);
        $this->assertSame('a', $handler());
    }

    /** A route matched twice rebuilds its handler once. */
    public function test_a_restored_handler_is_reused_on_the_next_match(): void
    {
        $router = $this->router();
        $router->get('/a', fn() => 'a');

        $this->assertSame(0, $this->loader()->cache($router));

        $warm = $this->router();
        $loader = $this->loader();
        $loader->load($warm);

        $first = $warm->findMatchingRoute(new Request('GET', '/a'))?->getHandler();
        $second = $warm->findMatchingRoute(new Request('GET', '/a'))?->getHandler();

        $this->assertInstanceOf(\Closure::class, $first);
        $this->assertSame($first, $second, 'a second match must not recompile the handler');
    }

    /**
     * A handler that captured the container is stored by name, not by value.
     *
     * Writing the container out would walk the whole object graph, and since
     * the router hangs off it holding every other handler, one closure would
     * drag in all of them and reach a raw Closure that cannot be serialized —
     * a single such route used to cost the application its entire cache.
     */
    public function test_a_handler_that_captured_the_container_is_cacheable(): void
    {
        $container = $this->container();
        $service = $container->resolve(ContainerInterface::class);

        $router = $this->router();
        $router->get('/wired', fn() => get_class($service));

        $loader = $this->loader($container);

        $this->assertSame(
            0,
            $loader->cache($router),
            'capturing the container must not block the cache: ' . implode(', ', $loader->uncacheableRoutes())
        );

        $contents = (string) file_get_contents($this->cacheFile());

        $this->assertStringNotContainsString(
            'ContainerClassResolver',
            $contents,
            'the container must be stored by name, not written into the file'
        );
    }

    /** And it comes back pointed at the live container, not a copy of the cached one. */
    public function test_a_captured_container_is_resolved_again_on_load(): void
    {
        $container = $this->container();
        $service = $container->resolve(ContainerInterface::class);

        $router = $this->router();
        $router->get('/wired', fn() => $service);

        $this->assertSame(0, $this->loader($container)->cache($router));

        $warm = $this->router();
        $this->loader($container)->load($warm);

        $route = $warm->findMatchingRoute(new Request('GET', '/wired'));

        $this->assertNotNull($route);

        $handler = $route->getHandler();

        $this->assertInstanceOf(\Closure::class, $handler);
        $this->assertSame(
            $service,
            $handler(),
            'the restored handler must hold the live container, not a rebuilt one'
        );
    }

    /**
     * A handler bound to the router — the shape a provider produces when it
     * registers a route from inside a method — is detached before writing and
     * bound to the live router on the way back.
     */
    public function test_a_handler_bound_to_the_router_is_rebound_on_load(): void
    {
        $router = $this->router();
        $container = $this->container($router);

        $router->get('/bound', \Closure::bind(
            fn() => 'from a bound handler',
            $router,
            Router::class
        ));

        $loader = $this->loader($container);

        $this->assertSame(
            0,
            $loader->cache($router),
            'a bound handler must not block the cache: ' . implode(', ', $loader->uncacheableRoutes())
        );

        $warm = $this->router();
        $this->loader($this->container($warm))->load($warm);

        $route = $warm->findMatchingRoute(new Request('GET', '/bound'));

        $this->assertNotNull($route);

        $handler = $route->getHandler();

        $this->assertInstanceOf(\Closure::class, $handler);
        $this->assertSame('from a bound handler', $handler());

        $this->assertSame(
            $warm,
            (new \ReflectionFunction($handler))->getClosureThis(),
            'the handler must be bound to the router that loaded it'
        );
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
