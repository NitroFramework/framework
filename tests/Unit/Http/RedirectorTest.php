<?php

namespace Tests\Unit\Http;

use Nitro\Container\Container;
use Nitro\Http\RedirectResponse;
use Nitro\Http\Redirector;
use Nitro\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Locks the Laravel-style fluent redirect surface: redirect() with no URL
 * yields a Redirector, and ->to()/->route()/->back()/->intended() each build a
 * RedirectResponse pointed at the right place.
 */
class RedirectorTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    public function test_redirect_helper_returns_redirector_or_response(): void
    {
        $this->assertInstanceOf(Redirector::class, redirect());
        $this->assertInstanceOf(RedirectResponse::class, redirect('/somewhere'));
    }

    public function test_to_sets_location_and_status(): void
    {
        $response = (new Redirector())->to('/dashboard', 303);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/dashboard', $response->header('Location'));
        $this->assertSame(303, $response->getStatusCode());
    }

    public function test_route_resolves_through_the_router(): void
    {
        Container::getInstance()->instance('router', new class {
            public function route(string $name, array $parameters = []): string
            {
                return '/users/' . ($parameters['id'] ?? '0');
            }
        });

        $response = (new Redirector())->route('users.show', ['id' => 5]);

        $this->assertSame('/users/5', $response->header('Location'));
    }

    public function test_back_uses_referer_then_fallback(): void
    {
        Container::getInstance()->instance('request', new Request('GET', '/', ['referer' => '/previous']));
        $this->assertSame('/previous', (new Redirector())->back()->header('Location'));

        Container::getInstance()->instance('request', new Request('GET', '/'));
        $this->assertSame('/home', (new Redirector())->back('/home')->header('Location'));
    }

    public function test_intended_consumes_stored_url_with_fallback(): void
    {
        Container::getInstance()->instance('auth', new class {
            public function getIntendedUrl(?string $default = null): ?string
            {
                return '/profile';
            }
        });

        $this->assertSame('/profile', (new Redirector())->intended('/dashboard')->header('Location'));
    }
}
