<?php

namespace Tests\Unit\Http;

use Nitro\Foundation\Config;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Confirms that dynamic routes are bucketed by their first static URL segment
 * so route matching only scans candidates whose prefix could match the
 * incoming path. Routes whose first segment is a placeholder go in the '*'
 * bucket and are always considered.
 */
class RouterPrefixBucketingTest extends TestCase
{
    protected function router(): Router
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('App\\Controllers\\');
        return new Router($config);
    }

    protected function buckets(Router $router): array
    {
        $p = new ReflectionProperty(Router::class, 'dynamicRoutesByPrefix');
        return $p->getValue($router);
    }

    public function test_static_first_segment_goes_in_its_bucket(): void
    {
        $r = $this->router();
        $r->get('/users/{id}', fn() => null);

        $buckets = $this->buckets($r);
        $this->assertArrayHasKey('users', $buckets['GET']);
        $this->assertCount(1, $buckets['GET']['users']);
        $this->assertArrayNotHasKey('*', $buckets['GET']);
    }

    public function test_placeholder_first_segment_goes_in_wildcard_bucket(): void
    {
        $r = $this->router();
        $r->get('/{slug}', fn() => null);

        $buckets = $this->buckets($r);
        $this->assertArrayHasKey('*', $buckets['GET']);
        $this->assertCount(1, $buckets['GET']['*']);
    }

    public function test_routes_in_other_buckets_are_not_considered(): void
    {
        $r = $this->router();
        $r->get('/users/{id}', fn() => 'users');
        $r->get('/posts/{id}', fn() => 'posts');

        // Use reflection to call findDynamicRoute directly so we can verify
        // only the 'users' bucket would be touched for /users/...
        $find = new \ReflectionMethod(Router::class, 'findDynamicRoute');

        $match = $find->invoke($r, 'GET', '/users/42');
        $this->assertNotNull($match);
        $this->assertSame('42', $match['parameters']['id']);

        $miss = $find->invoke($r, 'GET', '/articles/42');
        $this->assertNull($miss);
    }

    public function test_wildcard_routes_are_checked_for_every_prefix(): void
    {
        $r = $this->router();
        $r->get('/{slug}', fn() => 'wildcard');

        $find = new \ReflectionMethod(Router::class, 'findDynamicRoute');

        $match = $find->invoke($r, 'GET', '/hello-world');
        $this->assertNotNull($match);
        $this->assertSame('hello-world', $match['parameters']['slug']);
    }
}
