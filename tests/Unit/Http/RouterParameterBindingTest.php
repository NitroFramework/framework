<?php

namespace Tests\Unit\Http;

use Nitro\Foundation\Config;
use Nitro\Http\Request;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies that dynamic route matches are bound to declared parameter names so
 * controllers/closures receive them as named arguments, regardless of position.
 */
class RouterParameterBindingTest extends TestCase
{
    protected function router(): Router
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('App\\Controllers\\');

        return new Router($config);
    }

    protected function findDynamic(Router $router, string $method, string $path): ?array
    {
        $r = new ReflectionMethod(Router::class, 'findDynamicRoute');
        return $r->invoke($router, $method, $path);
    }

    public function test_single_param_is_keyed_by_name_and_index(): void
    {
        $router = $this->router();
        $router->get('/users/{id}', fn() => null);

        $result = $this->findDynamic($router, 'GET', '/users/42');

        $this->assertNotNull($result);
        // Named binding so reordered handler signatures still work.
        $this->assertArrayHasKey('id', $result['parameters']);
        $this->assertSame('42', $result['parameters']['id']);
        // Positional fallback so legacy handlers whose parameter names differ
        // from the URL placeholder still resolve via index.
        $this->assertArrayHasKey(0, $result['parameters']);
        $this->assertSame('42', $result['parameters'][0]);
    }

    public function test_multiple_params_carry_both_named_and_positional_keys(): void
    {
        $router = $this->router();
        $router->get('/posts/{slug}/comments/{id}', fn() => null);

        $result = $this->findDynamic($router, 'GET', '/posts/hello-world/comments/7');

        $this->assertSame('hello-world', $result['parameters']['slug']);
        $this->assertSame('7', $result['parameters']['id']);
        $this->assertSame('hello-world', $result['parameters'][0]);
        $this->assertSame('7', $result['parameters'][1]);
    }

    public function test_no_match_returns_null(): void
    {
        $router = $this->router();
        $router->get('/users/{id}', fn() => null);

        $this->assertNull($this->findDynamic($router, 'GET', '/users'));
        $this->assertNull($this->findDynamic($router, 'POST', '/users/1'));
    }

    public function test_static_routes_are_not_in_dynamic_table(): void
    {
        $router = $this->router();
        $router->get('/health', fn() => null);

        $this->assertNull($this->findDynamic($router, 'GET', '/health'));
    }
}
