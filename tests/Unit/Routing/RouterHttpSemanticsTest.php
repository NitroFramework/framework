<?php

namespace Tests\Unit\Routing;

use Nitro\Container\Container;
use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Foundation\Application;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Kernel;
use Nitro\Http\Request;
use Nitro\Routing\Contracts\ReportsAllowedMethods;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Two HTTP rules the router used to get wrong, both of which look like a
 * missing page from the outside.
 *
 * A request to a path that exists with the wrong verb is a 405, not a 404, and
 * RFC 9110 requires an Allow header naming the verbs that would have worked —
 * without it a client cannot discover what the path accepts, and a CORS
 * preflight has nothing to read. Both used to come back as a bare 404.
 *
 * And a trailing slash does not make a different resource: /about/ and /about
 * are the same page. A link written with one 404'd.
 */
class RouterHttpSemanticsTest extends TestCase
{
    private function router(): Router
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        return new Router($config, new RouteTypes());
    }

    // ─── Allow / 405 ──────────────────────────────────────────────────────

    public function test_the_router_reports_the_verbs_a_path_answers(): void
    {
        $router = $this->router();
        $router->get('/users', fn () => 'list');
        $router->post('/users', fn () => 'create');

        $this->assertSame(['GET', 'HEAD', 'POST'], $router->allowedMethods('/users'));
    }

    /** HEAD is served wherever GET is, so it belongs in Allow. */
    public function test_head_is_reported_alongside_get(): void
    {
        $router = $this->router();
        $router->get('/ping', fn () => 'pong');

        $this->assertSame(['GET', 'HEAD'], $router->allowedMethods('/ping'));
    }

    public function test_a_dynamic_path_reports_its_verbs_too(): void
    {
        $router = $this->router();
        $router->delete('/users/{user}', fn () => 'kill');

        $this->assertSame(['DELETE'], $router->allowedMethods('/users/7'));
    }

    public function test_an_unknown_path_reports_nothing(): void
    {
        $router = $this->router();
        $router->get('/users', fn () => 'list');

        $this->assertSame([], $router->allowedMethods('/nothing-here'));
    }

    public function test_the_router_offers_the_capability(): void
    {
        $this->assertInstanceOf(ReportsAllowedMethods::class, $this->router());
    }

    /** The whole point: the kernel turns that into a 405 with the header. */
    public function test_the_wrong_verb_gets_a_405_with_an_allow_header(): void
    {
        $router = $this->router();
        $router->get('/users', fn () => 'list');

        $response = $this->kernel($router)->handle(new Request('POST', '/users'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, HEAD', $response->header('Allow'));
    }

    /** A path nobody serves is still a 404, header and all. */
    public function test_an_unknown_path_is_still_a_404(): void
    {
        $router = $this->router();
        $router->get('/users', fn () => 'list');

        $response = $this->kernel($router)->handle(new Request('POST', '/nothing-here'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($response->header('Allow'));
    }

    /** OPTIONS is answered rather than refused — this is what a preflight reads. */
    public function test_options_is_answered_with_the_allowed_verbs(): void
    {
        $router = $this->router();
        $router->get('/users', fn () => 'list');
        $router->post('/users', fn () => 'create');

        $response = $this->kernel($router)->handle(new Request('OPTIONS', '/users'));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('GET, HEAD, POST', $response->header('Allow'));
    }

    /**
     * A router that cannot report its verbs must not break: the capability is
     * optional, and the framework falls back to the old 404.
     */
    public function test_a_router_without_the_capability_still_404s(): void
    {
        $router = $this->createMock(Router::class);
        $router->method('findMatchingRoute')->willReturn(null);
        $router->method('getMiddlewareAlias')->willReturn(null);
        $router->method('getMiddlewareAliases')->willReturn([]);

        $response = $this->kernel($router)->handle(new Request('POST', '/users'));

        $this->assertSame(404, $response->getStatusCode());
    }

    // ─── Trailing slash ───────────────────────────────────────────────────

    public function test_a_trailing_slash_matches_the_same_route(): void
    {
        $router = $this->router();
        $router->get('/about', fn () => 'about');

        $this->assertNotNull($router->findMatchingRoute(new Request('GET', '/about/')));
    }

    public function test_the_root_still_matches(): void
    {
        $router = $this->router();
        $router->get('/', fn () => 'home');

        $this->assertNotNull($router->findMatchingRoute(new Request('GET', '/')));
    }

    public function test_a_trailing_slash_matches_a_dynamic_route(): void
    {
        $router = $this->router();
        $router->get('/users/{user}', fn () => 'show');

        $route = $router->findMatchingRoute(new Request('GET', '/users/7/'));

        $this->assertNotNull($route);
        $this->assertSame('7', $route->getParameters()['user'] ?? null);
    }

    /** The slash must not change the answer to what a path accepts either. */
    public function test_allowed_methods_ignores_a_trailing_slash(): void
    {
        $router = $this->router();
        $router->post('/users', fn () => 'create');

        $this->assertSame(['POST'], $router->allowedMethods('/users/'));
    }

    private function kernel(object $router): Kernel
    {
        $container = new Container();

        $config = new class implements ConfigRepository {
            public function has(string $key): bool { return $key === 'app.debug'; }
            public function get(string $key, mixed $default = null): mixed { return $key === 'app.debug' ? false : $default; }
            public function all(): array { return ['app.debug' => false]; }
            public function set(string $key, mixed $value): void {}
        };

        $container->instance(ExceptionHandler::class, new ExceptionHandler($config, $container));

        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);
        $app->method('isDebug')->willReturn(false);

        return new Kernel($app, $router, new RouteDispatcher($container->resolve(ClassResolver::class), $container->resolve(CallableInvoker::class)));
    }
}
