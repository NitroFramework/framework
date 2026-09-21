<?php

namespace Tests\Unit\Routing;

use Nitro\Container\Container;
use Nitro\Container\Contracts\CallableInvoker;
use Nitro\Container\Contracts\ClassResolver;
use Nitro\Foundation\Application;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Kernel;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Http\Request;
use Nitro\Routing\Route;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Excluding one member of a middleware group, and resources with no id.
 *
 * A group is all-or-nothing without withoutMiddleware(): a webhook that must
 * skip CSRF but keep sessions and cookies has to leave 'web' entirely and
 * re-list what it wanted.
 *
 * A singleton resource is one with no id — /profile, /settings. Its member
 * routes drop the {parameter} the plural form carries.
 */
class WithoutMiddlewareAndSingletonTest extends TestCase
{
    private function router(): Router
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        return new Router($config, new RouteTypes());
    }

    /** @return array<int, string> "VERB /path" for every registered route */
    private function registered(Router $router): array
    {
        $found = [];

        foreach ($router->getRoutes() as $verb => $paths) {
            foreach (array_keys($paths) as $path) {
                $found[] = "{$verb} {$path}";
            }
        }

        sort($found);

        return $found;
    }

    // ─── withoutMiddleware ────────────────────────────────────────────────

    public function test_a_route_excludes_nothing_by_default(): void
    {
        $router = $this->router();
        $router->get('/hooks', fn () => 'ok');

        $this->assertSame([], $router->findMatchingRoute(new Request('GET', '/hooks'))->excludedMiddleware());
    }

    public function test_without_middleware_records_the_exclusion(): void
    {
        $router = $this->router();
        $router->get('/hooks', fn () => 'ok')->withoutMiddleware('csrf');

        $this->assertSame(['csrf'], $router->findMatchingRoute(new Request('GET', '/hooks'))->excludedMiddleware());
    }

    public function test_several_can_be_excluded_at_once(): void
    {
        $router = $this->router();
        $router->get('/hooks', fn () => 'ok')->withoutMiddleware(['csrf', 'auth']);

        $this->assertSame(['csrf', 'auth'], $router->findMatchingRoute(new Request('GET', '/hooks'))->excludedMiddleware());
    }

    public function test_exclusions_survive_the_route_cache(): void
    {
        $router = $this->router();
        $router->get('/hooks', 'HookController@handle')->withoutMiddleware('csrf');

        $warm = $this->router();
        $warm->loadCachedRoutes([
            'routes'            => $router->getRoutes(),
            'named_routes'      => $router->getNamedRoutes(),
            'static_routes'     => $router->getCompiledRoutes()['static'],
            'dynamic_routes'    => $router->getCompiledRoutes()['dynamic'],
            'dynamic_by_prefix' => $router->getCompiledRoutes()['byPrefix'],
            'compiled_patterns' => $router->getCompiledRoutes()['patterns'],
        ]);

        $this->assertSame(['csrf'], $warm->findMatchingRoute(new Request('GET', '/hooks'))->excludedMiddleware());
    }

    /** The point of it: the kernel actually drops the excluded one. */
    public function test_the_kernel_drops_an_excluded_group_member(): void
    {
        $route = new Route(Route::TYPE_CLOSURE, fn () => '', [], [], ['web']);
        $route->setExcludedMiddleware([VerifyCsrfToken::class]);

        $gathered = $this->gather($route);

        $this->assertNotEmpty($gathered, 'the web group should still expand');
        $this->assertNotContains(VerifyCsrfToken::class, $gathered);
    }

    public function test_the_rest_of_the_group_survives(): void
    {
        $full = $this->gather(new Route(Route::TYPE_CLOSURE, fn () => '', [], [], ['web']));

        $route = new Route(Route::TYPE_CLOSURE, fn () => '', [], [], ['web']);
        $route->setExcludedMiddleware([VerifyCsrfToken::class]);

        $this->assertCount(count($full) - 1, $this->gather($route));
    }

    /** An alias excludes the class it stands for. */
    public function test_excluding_by_alias_removes_the_class(): void
    {
        $route = new Route(Route::TYPE_CLOSURE, fn () => '', [], [], ['web']);
        $route->setExcludedMiddleware(['csrf']);

        $this->assertNotContains(VerifyCsrfToken::class, $this->gather($route, ['csrf' => VerifyCsrfToken::class]));
    }

    /** @return array<int, string> */
    private function gather(Route $route, array $aliases = []): array
    {
        $container = new Container();

        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        $router = $this->createMock(Router::class);
        $router->method('getMiddlewareAlias')->willReturnCallback(
            static fn (string $name): ?string => $aliases[$name] ?? null
        );
        $router->method('getMiddlewareAliases')->willReturn($aliases);

        $kernel = new Kernel($app, $router, new RouteDispatcher($container->resolve(ClassResolver::class), $container->resolve(CallableInvoker::class)));

        return (new ReflectionMethod($kernel, 'gatherMiddleware'))->invoke($kernel, $route);
    }

    // ─── singleton resources ──────────────────────────────────────────────

    public function test_a_singleton_has_no_id_in_its_paths(): void
    {
        $router = $this->router();
        $router->singleton('profile', 'ProfileController');

        $this->assertSame(
            ['DELETE /profile', 'GET /profile', 'GET /profile/edit', 'PATCH /profile', 'PUT /profile'],
            $this->registered($router),
        );
    }

    public function test_a_singleton_names_its_routes(): void
    {
        $router = $this->router();
        $router->singleton('profile', 'ProfileController');

        $this->assertNotNull($router->getRouteByName('profile.show'));
        $this->assertNotNull($router->getRouteByName('profile.edit'));
        $this->assertNotNull($router->getRouteByName('profile.update'));
    }

    /** An API singleton has no edit form, the way apiResource has no create. */
    public function test_an_api_singleton_drops_the_edit_route(): void
    {
        $router = $this->router();
        $router->apiSingleton('profile', 'ProfileController');

        $this->assertSame(
            ['DELETE /profile', 'GET /profile', 'PATCH /profile', 'PUT /profile'],
            $this->registered($router),
        );
    }

    public function test_a_singleton_reaches_its_controller_action(): void
    {
        $router = $this->router();
        $router->singleton('profile', 'ProfileController');

        $route = $router->findMatchingRoute(new Request('GET', '/profile'));

        $this->assertNotNull($route);
        $this->assertSame('show', $route->getControllerMethod());
    }

    public function test_only_still_limits_a_singleton(): void
    {
        $router = $this->router();
        $router->singleton('profile', 'ProfileController', ['only' => ['show']]);

        $this->assertSame(['GET /profile'], $this->registered($router));
    }
}
