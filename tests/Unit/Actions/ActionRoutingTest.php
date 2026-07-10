<?php

namespace Tests\Unit\Actions;

use Nitro\Actions\Action;
use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Http\Request;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;

/** A single-action class used as the fixture for the tests below. */
class GreetAction extends Action
{
    public function handle(string $name = 'World'): string
    {
        return "Hello {$name}";
    }
}

/**
 * End-to-end coverage: an Action dispatched through the real Router + booted
 * Application, exercising the dispatcher's action pipeline hook.
 */
class ActionRoutingTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        Container::reset();
        $this->app = new Application(dirname(__DIR__, 3));
        $this->app->bootstrap();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
    }

    public function test_run_resolves_and_calls_handle_positionally(): void
    {
        $this->assertSame('Hello Nitro', GreetAction::run('Nitro'));
    }

    public function test_make_returns_an_autowired_instance(): void
    {
        $this->assertInstanceOf(GreetAction::class, GreetAction::make());
    }

    public function test_action_class_works_as_a_route_handler(): void
    {
        $container = $this->app->getContainer();

        $router = $container->get(Router::class);
        $router->get('/greet/{name}', GreetAction::class);

        $route = $router->findMatchingRoute(new Request('GET', '/greet/Nitro'));
        $this->assertNotNull($route, 'action class should register as a route handler');

        $result = $container->get(RouteDispatcher::class)
            ->dispatchToHandler($route, new Request('GET', '/greet/Nitro'));

        $this->assertSame('Hello Nitro', $result);
    }
}
