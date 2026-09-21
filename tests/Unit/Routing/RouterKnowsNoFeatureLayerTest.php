<?php

namespace Tests\Unit\Routing;

use Nitro\Container\Container;
use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Config;
use Nitro\Http\Request;
use Nitro\Routing\Contracts\RouteType;
use Nitro\Routing\Route;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The routing layer must not know what a feature layer is.
 *
 * It knew about exactly one: Livewire had a Route constant, two Route methods,
 * a static Route factory, a branch in Router::parseHandler(), an arm in
 * Router::createRoute() and an arm in the dispatcher's match — nine places in
 * three files. That is a layer the router cannot be shipped without, and it
 * was there only because it was the first layer to need a new kind of route.
 *
 * So the fifth kind is not Livewire's; it is anybody's, and this test uses a
 * type the framework has never heard of to prove the seam carries a route the
 * whole way: registration, caching, matching, dispatch.
 */
class RouterKnowsNoFeatureLayerTest extends TestCase
{
    /**
     * The guard that matters. Nine references were removed; nothing stops a
     * tenth being added, except this.
     */
    public function test_no_routing_source_file_names_a_feature_layer(): void
    {
        $offenders = [];

        foreach ($this->routingSources() as $file) {
            $source = (string) file_get_contents($file);

            foreach (['livewire', 'blaze', 'htmx'] as $layer) {
                if (stripos($source, $layer) !== false) {
                    $offenders[] = basename($file) . " mentions '{$layer}'";
                }
            }
        }

        $this->assertSame([], $offenders, 'the routing layer must name no feature layer, in code or in comments');
    }

    /** A contributed type carries a route from registration through to dispatch. */
    public function test_a_contributed_route_type_is_registered_matched_and_dispatched(): void
    {
        $types = (new RouteTypes())->add($this->feedType());

        $router = $this->router($types);
        $router->get('/feed/latest', ['feed' => 'latest-posts']);

        $route = $router->findMatchingRoute(new Request('GET', '/feed/latest'));

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('feed', $route->getType());
        $this->assertSame('latest-posts', $route->getHandler());

        $container  = new Container();
        $dispatcher = new RouteDispatcher(
            $container->resolve(ClassResolver::class),
            $container->resolve(CallableInvoker::class),
            $types,
        );

        $this->assertSame(
            'feed: latest-posts',
            $dispatcher->dispatchToHandler($route, new Request('GET', '/feed/latest')),
        );
    }

    /** URL parameters reach a contributed type the same as any other route. */
    public function test_a_contributed_route_type_receives_url_parameters(): void
    {
        $types = (new RouteTypes())->add($this->feedType());

        $router = $this->router($types);
        $router->get('/feed/{tag}', ['feed' => 'tagged']);

        $route = $router->findMatchingRoute(new Request('GET', '/feed/php'));

        $this->assertNotNull($route);
        $this->assertSame('php', $route->getParameters()['tag'] ?? null);
    }

    /**
     * A handler nobody claims is still an error, and an unclaimed type still
     * fails loudly — the seam must not turn a typo into a silent 500 later.
     */
    public function test_an_unclaimed_type_fails_where_it_used_to(): void
    {
        $router = $this->router(new RouteTypes());

        $this->expectException(\InvalidArgumentException::class);
        $router->get('/feed/latest', ['feed' => 'latest-posts']);
    }

    public function test_the_dispatcher_rejects_a_type_no_layer_claims(): void
    {
        $container  = new Container();
        $dispatcher = new RouteDispatcher(
            $container->resolve(ClassResolver::class),
            $container->resolve(CallableInvoker::class),
            new RouteTypes(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown route type: feed');

        $dispatcher->dispatchToHandler(
            Route::ofType('feed', 'latest-posts'),
            new Request('GET', '/feed/latest'),
        );
    }

    private function router(RouteTypes $types): Router
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        return new Router($config, $types);
    }

    private function feedType(): RouteType
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

    /** @return list<string> */
    private function routingSources(): array
    {
        $files = [];

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                dirname(__DIR__, 3) . '/src/Routing',
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($tree as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'expected to find the routing sources');

        return $files;
    }
}
