<?php

namespace Tests\Unit\Http;

use Nitro\Container\Container;
use Nitro\Foundation\Application;
use Nitro\Http\Kernel;
use Nitro\Http\Middleware\AddQueuedCookiesToResponse;
use Nitro\Http\Middleware\EncryptCookies;
use Nitro\Http\Middleware\VerifyCsrfToken;
use Nitro\Routing\Route;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\Router;
use Nitro\Session\Middleware\StartSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Middleware that depend on each other must run in the right order however a
 * route listed them.
 *
 * VerifyCsrfToken reads the token off the session StartSession opened, and an
 * authenticator needs that session to know who is logged in. A route written
 * as ->middleware(['auth', 'web']) reads perfectly sensibly, and used to run
 * the auth check against a session that did not exist yet — which does not
 * error, it just finds nobody logged in.
 *
 * Only listed middleware are reordered, and only past one another: anything
 * unlisted keeps the position the route gave it, because the route author had
 * a reason for it and this has none.
 */
class MiddlewarePriorityTest extends TestCase
{
    /** The middleware the kernel would run, in order, for a route declaring these. */
    private function gathered(array $declared, array $aliases = []): array
    {
        $container = new Container();

        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        $router = $this->createMock(Router::class);
        $router->method('getMiddlewareAlias')->willReturnCallback(
            static fn (string $name): ?string => $aliases[$name] ?? null
        );
        $router->method('getMiddlewareAliases')->willReturn($aliases);

        $kernel = new Kernel($app, $router, new RouteDispatcher($container));

        $gather = new ReflectionMethod($kernel, 'gatherMiddleware');

        return $gather->invoke($kernel, new Route(Route::TYPE_CLOSURE, fn () => '', [], [], $declared));
    }

    public function test_the_web_group_already_runs_in_order(): void
    {
        $this->assertSame(
            [EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, VerifyCsrfToken::class],
            $this->gathered(['web']),
        );
    }

    /** The case this exists for: a session-dependent middleware listed first. */
    public function test_a_session_dependent_middleware_is_moved_after_the_session(): void
    {
        $aliases = ['auth' => AuthStub::class];

        $container = new Container();
        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        $router = $this->createMock(Router::class);
        $router->method('getMiddlewareAlias')->willReturnCallback(
            static fn (string $name): ?string => $aliases[$name] ?? null
        );
        $router->method('getMiddlewareAliases')->willReturn($aliases);

        $kernel = new Kernel($app, $router, new RouteDispatcher($container));
        $kernel->addMiddlewarePriority(AuthStub::class, StartSession::class);

        $gather = new ReflectionMethod($kernel, 'gatherMiddleware');
        $order = $gather->invoke($kernel, new Route(Route::TYPE_CLOSURE, fn () => '', [], [], ['auth', 'web']));

        $session = array_search(StartSession::class, $order, true);
        $auth = array_search('auth', $order, true);

        $this->assertNotFalse($session, 'the web group should have expanded');
        $this->assertNotFalse($auth);
        $this->assertGreaterThan($session, $auth, 'auth must run after the session it reads');
    }

    /** Priority never reorders middleware it does not know about. */
    public function test_unlisted_middleware_keep_their_position(): void
    {
        $order = $this->gathered(['FirstCustom', 'web', 'SecondCustom']);

        $this->assertSame('FirstCustom', $order[0]);
        $this->assertSame('SecondCustom', $order[count($order) - 1]);
    }

    /** An alias and its class are the same entry in the priority list. */
    public function test_an_alias_resolves_to_its_class_for_ordering(): void
    {
        $order = $this->gathered(
            ['csrf', StartSession::class],
            ['csrf' => VerifyCsrfToken::class],
        );

        $this->assertSame([StartSession::class, 'csrf'], $order);
    }

    /** And 'alias:args' too — the arguments are not part of the name. */
    public function test_alias_arguments_do_not_hide_the_class(): void
    {
        $order = $this->gathered(
            ['csrf:one,two', StartSession::class],
            ['csrf' => VerifyCsrfToken::class],
        );

        $this->assertSame([StartSession::class, 'csrf:one,two'], $order);
    }

    public function test_priority_can_be_declared_from_outside_the_kernel(): void
    {
        $container = new Container();
        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        $router = $this->createMock(Router::class);
        $router->method('getMiddlewareAlias')->willReturn(null);

        $kernel = new Kernel($app, $router, new RouteDispatcher($container));
        $kernel->addMiddlewarePriority(AuthStub::class, StartSession::class);

        $priority = $kernel->getMiddlewarePriority();
        $session = array_search(StartSession::class, $priority, true);

        $this->assertSame(AuthStub::class, $priority[$session + 1]);
    }

    /** Declaring the same middleware twice moves it rather than duplicating it. */
    public function test_declaring_a_priority_twice_moves_it(): void
    {
        $container = new Container();
        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);

        $router = $this->createMock(Router::class);
        $router->method('getMiddlewareAlias')->willReturn(null);

        $kernel = new Kernel($app, $router, new RouteDispatcher($container));
        $kernel->addMiddlewarePriority(AuthStub::class, StartSession::class);
        $kernel->addMiddlewarePriority(AuthStub::class, EncryptCookies::class);

        $priority = $kernel->getMiddlewarePriority();

        $this->assertSame([AuthStub::class], array_values(array_filter(
            $priority,
            static fn (string $m): bool => $m === AuthStub::class,
        )));

        $encrypt = array_search(EncryptCookies::class, $priority, true);
        $this->assertSame(AuthStub::class, $priority[$encrypt + 1]);
    }
}

/** Stands in for a feature layer's session-dependent middleware. */
class AuthStub
{
}
