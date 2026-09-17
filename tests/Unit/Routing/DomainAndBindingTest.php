<?php

namespace Tests\Unit\Routing;

use Nitro\Exceptions\HttpException;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Request;
use Nitro\Routing\Route;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Host-constrained routes and explicit route-parameter binding.
 */
class DomainAndBindingTest extends TestCase
{
    private function router(): Router
    {
        $config = new class implements ConfigRepository {
            public function has(string $key): bool { return false; }
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'app.controllers_namespace' ? '' : $default;
            }
            public function all(): array { return []; }
            public function set(string $key, mixed $value): void {}
        };

        return new Router($config);
    }

    private function match(Router $router, string $host, string $path, string $method = 'GET'): ?Route
    {
        return $router->findMatchingRoute(
            new Request($method, $path, [], [], [], [], ['HTTP_HOST' => $host])
        );
    }

    private function ran(?Route $route): ?string
    {
        return $route === null ? null : ($route->getHandler())();
    }

    // ─── Domains ──────────────────────────────────────────

    public function test_route_is_limited_to_its_domain(): void
    {
        $router = $this->router();
        $router->get('/admin', fn () => 'admin')->domain('admin.example.com');

        $this->assertSame('admin', $this->ran($this->match($router, 'admin.example.com', '/admin')));
        $this->assertNull($this->match($router, 'example.com', '/admin'));
    }

    /** Two routes may share a path and differ only by host. */
    public function test_same_path_on_different_hosts(): void
    {
        $router = $this->router();
        $router->get('/', fn () => 'public');
        $router->get('/', fn () => 'admin')->domain('admin.example.com');

        $this->assertSame('public', $this->ran($this->match($router, 'example.com', '/')));
        $this->assertSame('admin', $this->ran($this->match($router, 'admin.example.com', '/')));
    }

    /** Registering the domain route first must work the same way. */
    public function test_same_path_with_domain_registered_first(): void
    {
        $router = $this->router();
        $router->get('/', fn () => 'admin')->domain('admin.example.com');
        $router->get('/', fn () => 'public');

        $this->assertSame('public', $this->ran($this->match($router, 'example.com', '/')));
        $this->assertSame('admin', $this->ran($this->match($router, 'admin.example.com', '/')));
    }

    public function test_domain_placeholder_is_captured(): void
    {
        $router = $this->router();
        $router->get('/', fn () => 'tenant')->domain('{account}.example.com');

        $route = $this->match($router, 'acme.example.com', '/');

        $this->assertNotNull($route);
        $this->assertSame('acme', $route->getParameter('account'));
    }

    public function test_domain_and_path_parameters_combine(): void
    {
        $router = $this->router();
        $router->get('/users/{id}', fn () => 'user')->domain('{account}.example.com');

        $route = $this->match($router, 'acme.example.com', '/users/7');

        $this->assertNotNull($route);
        $this->assertSame('acme', $route->getParameter('account'));
        $this->assertSame('7', $route->getParameter('id'));
    }

    public function test_port_is_ignored_when_matching_the_host(): void
    {
        $router = $this->router();
        $router->get('/', fn () => 'admin')->domain('admin.example.com');

        $this->assertNotNull($this->match($router, 'admin.example.com:8080', '/'));
    }

    public function test_host_matching_is_case_insensitive(): void
    {
        $router = $this->router();
        $router->get('/', fn () => 'admin')->domain('admin.example.com');

        $this->assertNotNull($this->match($router, 'ADMIN.Example.COM', '/'));
    }

    /** A dot in a host pattern is a dot, not "any character". */
    public function test_domain_dots_are_literal(): void
    {
        $router = $this->router();
        $router->get('/', fn () => 'admin')->domain('admin.example.com');

        $this->assertNull($this->match($router, 'adminXexample.com', '/'));
    }

    /** A placeholder matches one label, so it cannot swallow a dot. */
    public function test_domain_placeholder_matches_a_single_label(): void
    {
        $router = $this->router();
        $router->get('/', fn () => 'tenant')->domain('{account}.example.com');

        $this->assertNull($this->match($router, 'a.b.example.com', '/'));
    }

    public function test_domain_applies_to_a_group(): void
    {
        $router = $this->router();
        $router->group(['domain' => 'api.example.com'], function (Router $router) {
            $router->get('/ping', fn () => 'pong');
            $router->get('/health', fn () => 'ok');
        });
        $router->get('/ping', fn () => 'web ping');

        $this->assertSame('pong', $this->ran($this->match($router, 'api.example.com', '/ping')));
        $this->assertSame('ok', $this->ran($this->match($router, 'api.example.com', '/health')));
        $this->assertSame('web ping', $this->ran($this->match($router, 'example.com', '/ping')));
        $this->assertNull($this->match($router, 'example.com', '/health'));
    }

    /** Group state must not leak past the closure. */
    public function test_domain_group_does_not_leak(): void
    {
        $router = $this->router();
        $router->group(['domain' => 'api.example.com'], function (Router $router) {
            $router->get('/inside', fn () => 'inside');
        });
        $router->get('/outside', fn () => 'outside');

        $this->assertSame('outside', $this->ran($this->match($router, 'anything.test', '/outside')));
    }

    public function test_name_after_domain_applies_to_the_right_route(): void
    {
        $router = $this->router();
        $router->get('/', fn () => 'public')->name('home');
        $router->get('/', fn () => 'admin')->domain('admin.example.com')->name('admin.home');

        $this->assertTrue($router->has('home'));
        $this->assertTrue($router->has('admin.home'));
    }

    // ─── Explicit binding ─────────────────────────────────

    public function test_bind_replaces_the_parameter(): void
    {
        $router = $this->router();
        $router->bind('code', fn ($value) => strtoupper($value));
        $router->get('/x/{code}', fn () => 'ok');

        $route = $router->substituteBindings($this->match($router, 'example.com', '/x/abc'));

        $this->assertSame('ABC', $route->getParameter('code'));
    }

    public function test_bind_receives_the_route(): void
    {
        $router = $this->router();
        $router->bind('code', fn ($value, $route) => $route instanceof Route ? 'got-route' : 'no-route');
        $router->get('/x/{code}', fn () => 'ok');

        $route = $router->substituteBindings($this->match($router, 'example.com', '/x/abc'));

        $this->assertSame('got-route', $route->getParameter('code'));
    }

    public function test_unbound_parameters_are_left_alone(): void
    {
        $router = $this->router();
        $router->bind('code', fn ($value) => strtoupper($value));
        $router->get('/x/{other}', fn () => 'ok');

        $route = $router->substituteBindings($this->match($router, 'example.com', '/x/abc'));

        $this->assertSame('abc', $route->getParameter('other'));
    }

    public function test_binding_keys_ignore_case_and_separators(): void
    {
        $router = $this->router();
        $router->bind('user_id', fn ($value) => "resolved-{$value}");
        $router->get('/u/{userId}', fn () => 'ok');

        $route = $router->substituteBindings($this->match($router, 'example.com', '/u/7'));

        $this->assertSame('resolved-7', $route->getParameter('userId'));
    }

    public function test_has_binding_and_callback_accessors(): void
    {
        $router = $this->router();
        $router->bind('code', fn ($value) => $value);

        $this->assertTrue($router->hasBinding('code'));
        $this->assertFalse($router->hasBinding('nope'));
        $this->assertInstanceOf(\Closure::class, $router->getBindingCallback('code'));
        $this->assertNull($router->getBindingCallback('nope'));
    }

    public function test_model_binding_raises_404_when_missing(): void
    {
        $router = $this->router();
        $router->model('thing', FakeBindableModel::class);
        $router->get('/t/{thing}', fn () => 'ok');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('No query results for model');

        $router->substituteBindings($this->match($router, 'example.com', '/t/999'));
    }

    public function test_model_binding_resolves_an_existing_record(): void
    {
        $router = $this->router();
        $router->model('thing', FakeBindableModel::class);
        $router->get('/t/{thing}', fn () => 'ok');

        $route = $router->substituteBindings($this->match($router, 'example.com', '/t/1'));

        $this->assertInstanceOf(FakeBindableModel::class, $route->getParameter('thing'));
        $this->assertSame('1', $route->getParameter('thing')->id);
    }

    public function test_model_binding_missing_callback_replaces_the_404(): void
    {
        $router = $this->router();
        $router->model('thing', FakeBindableModel::class, fn ($value) => "fallback-{$value}");
        $router->get('/t/{thing}', fn () => 'ok');

        $route = $router->substituteBindings($this->match($router, 'example.com', '/t/999'));

        $this->assertSame('fallback-999', $route->getParameter('thing'));
    }

    public function test_substitute_bindings_is_a_no_op_without_binders(): void
    {
        $router = $this->router();
        $router->get('/x/{code}', fn () => 'ok');

        $route = $router->substituteBindings($this->match($router, 'example.com', '/x/abc'));

        $this->assertSame('abc', $route->getParameter('code'));
    }
}

/** Stand-in for a model, so the binding tests need no database. */
class FakeBindableModel
{
    public function __construct(public string $id) {}

    public static function find(mixed $id): ?self
    {
        return $id === '1' ? new self((string) $id) : null;
    }
}
