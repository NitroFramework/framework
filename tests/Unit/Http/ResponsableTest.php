<?php

namespace Tests\Unit\Http;

use Nitro\Container\Contracts\ContainerInterface;
use Nitro\Foundation\Application;
use Nitro\Foundation\Http\Kernel;
use Nitro\Http\Contracts\Responsable;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Routing\Route;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** A stand-in resource that renders itself. */
class FakeResource implements Responsable
{
    public function toResponse(Request $request): Response
    {
        return Response::json(['resource' => true, 'path' => $request->path()]);
    }
}

/** The Kernel turns a returned Responsable into its own Response (#3). */
class ResponsableTest extends TestCase
{
    public function test_kernel_converts_responsable_return_value(): void
    {
        $app        = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($this->createMock(ContainerInterface::class));
        $router     = $this->createMock(Router::class);
        $dispatcher = $this->createMock(RouteDispatcher::class);

        // The route handler returns a Responsable, not a Response.
        $dispatcher->method('dispatchToHandler')->willReturn(new FakeResource());

        $kernel  = new Kernel($app, $router, $dispatcher);
        $request = new Request('GET', '/widgets');

        $response = (new ReflectionMethod(Kernel::class, 'dispatchToHandler'))
            ->invoke($kernel, $this->createMock(Route::class), $request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(
            ['resource' => true, 'path' => '/widgets'],
            json_decode($response->getContent(), true)
        );
    }
}
