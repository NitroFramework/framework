<?php

namespace Tests\Unit\Http;

use Nitro\Container\Container;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Foundation\Application;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Kernel;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Routing\Route;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the global middleware stack covers, and what an unresolvable middleware
 * name does.
 *
 * Two properties, both invisible from the outside when broken:
 *
 * The stack wraps route matching rather than sitting inside it, so it runs on
 * requests that match nothing. Cross-cutting middleware — CORS, trusted
 * proxies, maintenance mode — has to apply to a 404 as much as to a hit; a
 * mistyped API URL that returns a 404 without CORS headers fails in the client
 * as a CORS error, which says nothing about the actual mistake.
 *
 * And a name that resolves to no middleware throws rather than being skipped.
 * Skipping made a misspelled guard indistinguishable from one that passed: the
 * route ran no check, and route:list still described it as protected.
 */
class GlobalMiddlewareScopeTest extends TestCase
{
    /** Middleware names that ran, in order, for the request under test. */
    private static array $ran = [];

    private function kernel(?Route $route): Kernel
    {
        self::$ran = [];

        $container = new Container();
        $container->instance(ExceptionHandler::class, new ExceptionHandler(
            new class implements ConfigRepository {
                public function has(string $key): bool { return $key === 'app.debug'; }
                public function get(string $key, mixed $default = null): mixed { return $key === 'app.debug' ? false : $default; }
                public function all(): array { return ['app.debug' => false]; }
                public function set(string $key, mixed $value): void {}
            },
            $container,
        ));

        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);
        $app->method('isDebug')->willReturn(false);

        $router = $this->createMock(Router::class);
        $router->method('findMatchingRoute')->willReturn($route);
        $router->method('getMiddlewareAlias')->willReturn(null);
        $router->method('getMiddlewareAliases')->willReturn([]);

        return new Kernel($app, $router, new RouteDispatcher($container));
    }

    private function request(): Request
    {
        return new Request('GET', '/anything');
    }

    // ─── Scope of the global stack ──────────────────────────────────────────

    public function test_global_middleware_runs_on_a_matched_route(): void
    {
        $kernel = $this->kernel(Route::closure(fn () => Response::html('ok')));
        $kernel->pushMiddleware(RecordingMiddleware::class);

        $kernel->handle($this->request());

        $this->assertSame([RecordingMiddleware::class], self::$ran);
    }

    public function test_global_middleware_runs_when_nothing_matches(): void
    {
        $kernel = $this->kernel(null);
        $kernel->pushMiddleware(RecordingMiddleware::class);

        $response = $kernel->handle($this->request());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            [RecordingMiddleware::class],
            self::$ran,
            'A 404 returned without running the global middleware stack.',
        );
    }

    public function test_global_middleware_can_add_a_header_to_a_404(): void
    {
        $kernel = $this->kernel(null);
        $kernel->pushMiddleware(CorsMiddleware::class);

        $response = $kernel->handle($this->request());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            '*',
            $response->header('Access-Control-Allow-Origin'),
            'A 404 was returned without the CORS header the global stack sets, so the '
            . 'client sees a CORS failure instead of the 404.',
        );
    }

    public function test_global_middleware_runs_before_route_middleware(): void
    {
        $kernel = $this->kernel(Route::closure(fn () => Response::html('ok'), [], [RouteMiddleware::class]));
        $kernel->pushMiddleware(RecordingMiddleware::class);

        $kernel->handle($this->request());

        $this->assertSame([RecordingMiddleware::class, RouteMiddleware::class], self::$ran);
    }

    // ─── Unresolvable names ─────────────────────────────────────────────────

    public function test_an_unknown_middleware_name_throws_rather_than_being_skipped(): void
    {
        $kernel = $this->kernel(Route::closure(fn () => Response::html('ok'), [], ['platfrom:admin']));

        $response = $kernel->handle($this->request());

        // It surfaces as a 500 rather than escaping handle(), because the
        // kernel converts every throwable into a response. The point is that
        // the route did not quietly serve its handler with no guard.
        $this->assertSame(500, $response->getStatusCode());
        $this->assertInstanceOf(RuntimeException::class, $kernel->lastException());
        $this->assertStringContainsString('platfrom', $kernel->lastException()->getMessage());
    }

    public function test_the_failure_names_the_registered_aliases(): void
    {
        $container = new Container();
        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        $router = $this->createMock(Router::class);
        $router->method('getMiddlewareAlias')->willReturn(null);
        $router->method('getMiddlewareAliases')->willReturn([
            'auth' => 'AuthMiddleware',
            'platform' => 'PlatformMiddleware',
        ]);

        $kernel = new Kernel($app, $router, new RouteDispatcher($container));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('auth, platform');

        (new \ReflectionMethod(Kernel::class, 'resolveRouteMiddleware'))->invoke($kernel, 'platfrom');
    }

    public function test_a_class_name_middleware_needs_no_alias(): void
    {
        $kernel = $this->kernel(Route::closure(fn () => Response::html('ok'), [], [RouteMiddleware::class]));

        $response = $kernel->handle($this->request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([RouteMiddleware::class], self::$ran);
    }

    public static function record(string $name): void
    {
        self::$ran[] = $name;
    }
}

/** Records that it ran, then passes the request along untouched. */
class RecordingMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        GlobalMiddlewareScopeTest::record(self::class);

        return $next($request);
    }
}

/** Distinguishes route-stack ordering from global-stack ordering. */
class RouteMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        GlobalMiddlewareScopeTest::record(self::class);

        return $next($request);
    }
}

/** Stands in for the real cross-cutting case: a header set on the way out. */
class CorsMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request)->header('Access-Control-Allow-Origin', '*');
    }
}
