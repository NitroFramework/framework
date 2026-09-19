<?php

namespace Tests\Unit\Routing;

use InvalidArgumentException;
use Nitro\Container\Container;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * A name with no route behind it.
 *
 * route() used to catch that and return the error message as though it were a
 * URL, so a link pointed at "/Route[dashboard] not found: …". In production it
 * did something worse and quieter: returned the home page. A "Download your
 * certificate" button that sends somebody to the marketing site, with nothing
 * logged and nothing to notice for months.
 *
 * A route name is not runtime data. The table is fixed at boot, so a miss is
 * always a programming error, and the only question is who finds out.
 */
class RouteHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config = new class implements ConfigRepository {
            public function get(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'app.controllers_namespace' => 'App\\Controllers',
                    'app.debug' => true,
                    default => $default,
                };
            }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function all(): array { return []; }
        };

        $router = new Router($config);
        $router->get('/courses/{slug}', fn () => 'course')->name('courses.show');
        $router->get('/basket', fn () => 'basket')->name('basket');

        Container::setInstance(new Container());
        $container = Container::getInstance();
        $container->instance('router', $router);
        $container->instance(ConfigRepository::class, $config);
        $container->instance('config', $config);
    }

    protected function tearDown(): void
    {
        Container::setInstance(new Container());

        parent::tearDown();
    }

    public function test_a_known_route_resolves(): void
    {
        $this->assertSame('/basket', route('basket'));
    }

    public function test_a_bare_parameter_does_not_need_an_array(): void
    {
        // route('courses.show', $course) and route('courses.show', 'a-slug')
        // are both ordinary things to write.
        $this->assertSame('/courses/food-safety', route('courses.show', 'food-safety'));
    }

    public function test_an_unknown_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dashboard');

        route('dashboard');
    }

    public function test_an_unknown_name_never_comes_back_as_a_url(): void
    {
        // The specific old failure: the message was returned as though it were
        // a URL, and the browser dutifully navigated to it.
        try {
            $url = route('dashboard');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true, 'it threw, which is the point');

            return;
        }

        $this->fail("route() returned [{$url}] instead of throwing");
    }
}
