<?php

namespace Tests\Unit\Routing;

use Nitro\Container\Container;
use Nitro\Container\ContainerClassResolver;
use Nitro\Foundation\Config;
use Nitro\Foundation\PathRegistry;
use Nitro\Http\Request;
use Nitro\Routing\RouteLoader;
use Nitro\Routing\RouteTypes;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * A group's URI prefix and its route names are independent, and so are its URI
 * prefix and its middleware stack.
 *
 * Both used to be inferred. A prefix with no explicit name silently became a
 * name prefix, so `Route::group(['prefix' => 'nitro/forge'])` renamed every
 * route inside it to `nitro/forge.*` and `route('forge.panel')` threw. And a
 * route file registered under a prefix took that prefix as its middleware
 * group name, so any package mounting routes asked for a group nobody had
 * registered and every request to it 500'd.
 */
class GroupPrefixAndNameTest extends TestCase
{
    private function router(): Router
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        return new Router($config, new RouteTypes());
    }

    private function route(Router $router, string $path, string $method = 'GET'): ?\Nitro\Routing\Route
    {
        return $router->findMatchingRoute(new Request($method, $path));
    }

    public function test_a_group_prefix_does_not_become_a_route_name_prefix(): void
    {
        $router = $this->router();

        $router->group(['prefix' => 'admin'], function () use ($router) {
            $router->get('/emails', fn () => null)->name('emails');
        });

        $this->assertSame('emails', $this->route($router, '/admin/emails')->getName());
        $this->assertArrayHasKey('emails', $router->getNamedRoutes());
        $this->assertArrayNotHasKey('admin.emails', $router->getNamedRoutes());
    }

    /** A prefix containing a slash must not leak into a route name at all. */
    public function test_a_slashed_prefix_leaves_names_alone(): void
    {
        $router = $this->router();

        $router->group(['prefix' => 'nitro/forge'], function () use ($router) {
            $router->get('/', fn () => null)->name('forge.panel');
        });

        $this->assertNotNull($this->route($router, '/nitro/forge'));
        $this->assertArrayHasKey('forge.panel', $router->getNamedRoutes());
    }

    /**
     * A group's index route is reachable. `get('/')` under a prefix used to
     * register as `/admin/`, which nothing could match — the request path is
     * normalized before matching, the registered path never was.
     */
    public function test_a_group_index_route_registers_without_a_trailing_slash(): void
    {
        $router = $this->router();

        $router->group(['prefix' => 'admin'], function () use ($router) {
            $router->get('/', fn () => null)->name('admin.home');
        });

        $this->assertNotNull($this->route($router, '/admin'), 'the index route must match');
        $this->assertNotNull($this->route($router, '/admin/'), 'and match with a trailing slash');
        $this->assertArrayHasKey('/admin', $router->getRoutes()['GET']);
    }

    public function test_an_explicit_group_name_still_prefixes_names(): void
    {
        $router = $this->router();

        $router->group(['prefix' => 'admin', 'name' => 'admin.'], function () use ($router) {
            $router->get('/emails', fn () => null)->name('emails');
        });

        $this->assertArrayHasKey('admin.emails', $router->getNamedRoutes());
    }

    public function test_explicit_group_names_nest(): void
    {
        $router = $this->router();

        $router->group(['prefix' => 'admin', 'name' => 'admin.'], function () use ($router) {
            $router->group(['prefix' => 'reports', 'name' => 'reports.'], function () use ($router) {
                $router->get('/daily', fn () => null)->name('daily');
            });
        });

        $this->assertNotNull($this->route($router, '/admin/reports/daily'));
        $this->assertArrayHasKey('admin.reports.daily', $router->getNamedRoutes());
    }

    /**
     * A package's route file gets the `web` stack, not a middleware group
     * named after wherever it happens to be mounted.
     */
    public function test_an_added_route_file_loads_under_the_web_stack(): void
    {
        $file = sys_get_temp_dir() . '/nitro-pkg-routes-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, <<<'PHP'
            <?php
            $router->group(['prefix' => 'nitro/forge', 'name' => ''], function () use ($router) {
                $router->get('/', fn () => null)->name('forge.panel');
            });
            PHP);

        $loader = $this->loader();
        $loader->addRouteFile($file);

        $router = $this->router();
        $loader->loadFromFile($router);

        $route = $this->route($router, '/nitro/forge');

        $this->assertNotNull($route);
        $this->assertContains('web', $route->getMiddleware());
        $this->assertNotContains('nitro/forge', $route->getMiddleware());
        $this->assertSame('forge.panel', $route->getName());

        @unlink($file);
    }

    private function loader(): RouteLoader
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn(true); // app.debug=true, so no cache

        $paths = new class extends PathRegistry {
            public function __construct() {}
            public function base(string $path = ''): string { return sys_get_temp_dir() . '/nitro-none/' . $path; }
            public function cache(string $path = ''): string { return sys_get_temp_dir() . '/nitro-none/' . $path; }
            public function config(string $path = ''): string { return sys_get_temp_dir() . '/nitro-none/' . $path; }
        };

        return new RouteLoader($paths, $config, new ContainerClassResolver(new Container()));
    }
}
