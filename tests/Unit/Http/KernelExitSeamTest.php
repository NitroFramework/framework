<?php

namespace Tests\Unit\Http;

use Nitro\Container\Container;
use Nitro\Exceptions\ExceptionHandler;
use Nitro\Exceptions\HttpException;
use Nitro\Foundation\Application;
use Nitro\Foundation\Contracts\ConfigRepository;
use Nitro\Http\Exceptions\HttpResponseException;
use Nitro\Http\Kernel;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Routing\Route;
use Nitro\Routing\RouteDispatcher;
use Nitro\Routing\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every way a request can end must pass the responseReady seam.
 *
 * Hooks registered there do work the response is incomplete without — the
 * session provider emits its Set-Cookie header from one. A path that returns
 * without firing them produces a response that looks correct and is missing a
 * header, which no assertion about status or body will notice.
 *
 * The kernel previously fired them on three of its five exits. The two it
 * missed were the ordinary HTML error page and the 404, so a first-time
 * visitor whose request threw had their session written to disk under an id
 * the browser was never told.
 *
 * One test per exit. ResponseSeamGuardTest covers the structural half — that a
 * new exit cannot be added without going through the same funnel.
 */
class KernelExitSeamTest extends TestCase
{
    /** Names of the seams each hook fired into, in order, for the request under test. */
    private array $fired = [];

    private function config(array $items = []): ConfigRepository
    {
        return new class ($items + ['app.debug' => false]) implements ConfigRepository {
            public function __construct(private array $items = []) {}

            public function has(string $key): bool { return array_key_exists($key, $this->items); }
            public function get(string $key, mixed $default = null): mixed { return $this->items[$key] ?? $default; }
            public function all(): array { return $this->items; }
            public function set(string $key, mixed $value): void { $this->items[$key] = $value; }
        };
    }

    /**
     * A kernel wired to a real container and dispatcher, with the router stubbed
     * to return $route (or null, for the unmatched case).
     */
    private function kernel(?Route $route, bool $debug = false): Kernel
    {
        $container = new Container();
        $container->instance(ExceptionHandler::class, new ExceptionHandler($this->config(), $container));

        $app = $this->createMock(Application::class);
        $app->method('getContainer')->willReturn($container);
        $app->method('isDebug')->willReturn($debug);

        $router = $this->createMock(Router::class);
        $router->method('findMatchingRoute')->willReturn($route);
        $router->method('getMiddlewareAlias')->willReturn(null);
        $router->method('getMiddlewareAliases')->willReturn([]);

        $kernel = new Kernel($app, $router, new RouteDispatcher($container));

        $this->fired = [];
        $kernel->requestReceived(function (): void { $this->fired[] = 'requestReceived'; });
        $kernel->responseReady(function (): void { $this->fired[] = 'responseReady'; });
        $kernel->terminating(function (): void { $this->fired[] = 'terminating'; });

        return $kernel;
    }

    private function request(array $headers = [], string $path = '/somewhere'): Request
    {
        return new Request('GET', $path, $headers);
    }

    // ─── One test per exit ──────────────────────────────────────────────────

    public function test_a_matched_route_fires_the_seam(): void
    {
        $kernel = $this->kernel(Route::closure(fn () => Response::html('ok')));

        $response = $kernel->handle($this->request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['requestReceived', 'responseReady'], $this->fired);
    }

    public function test_an_unmatched_route_fires_the_seam(): void
    {
        $kernel = $this->kernel(null);

        $response = $kernel->handle($this->request());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertContains('responseReady', $this->fired, 'A 404 skipped the responseReady seam.');
    }

    public function test_an_http_exception_from_the_handler_fires_the_seam(): void
    {
        $kernel = $this->kernel(Route::closure(function (): never {
            throw new HttpException(403, 'Nope');
        }));

        $response = $kernel->handle($this->request());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertContains('responseReady', $this->fired, 'An HTTP exception skipped the responseReady seam.');
    }

    public function test_an_unhandled_throwable_fires_the_seam(): void
    {
        $kernel = $this->kernel(Route::closure(function (): never {
            throw new RuntimeException('boom');
        }));

        $response = $kernel->handle($this->request());

        $this->assertSame(500, $response->getStatusCode());
        $this->assertContains('responseReady', $this->fired, 'A 500 skipped the responseReady seam.');
    }

    public function test_a_short_circuited_response_fires_the_seam(): void
    {
        $kernel = $this->kernel(Route::closure(function (): never {
            throw new HttpResponseException(Response::json(['errors' => ['email' => 'Required']], 422));
        }));

        $response = $kernel->handle($this->request());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertContains('responseReady', $this->fired);
    }

    public function test_an_htmx_failure_fires_the_seam(): void
    {
        $kernel = $this->kernel(Route::closure(function (): never {
            throw new RuntimeException('boom');
        }));

        $kernel->handle($this->request(['hx-request' => 'true']));

        $this->assertContains('responseReady', $this->fired, 'An HTMX error skipped the responseReady seam.');
    }

    // ─── What the seam is allowed to do to the request ──────────────────────

    public function test_the_seam_runs_exactly_once_per_request(): void
    {
        $kernel = $this->kernel(null);

        $kernel->handle($this->request());

        $this->assertSame(
            1,
            count(array_filter($this->fired, fn (string $seam): bool => $seam === 'responseReady')),
            'The responseReady seam ran more than once for a single request.',
        );
    }

    public function test_request_received_does_not_fire_after_the_response_is_built(): void
    {
        $kernel = $this->kernel(Route::closure(fn () => Response::html('ok')));

        $kernel->handle($this->request());

        $this->assertSame('requestReceived', $this->fired[0] ?? null);
    }

    public function test_a_failing_hook_is_reported_but_keeps_the_response(): void
    {
        $kernel = $this->kernel(Route::closure(fn () => Response::html('ok')));
        $kernel->responseReady(function (): never {
            throw new RuntimeException('hook exploded');
        });

        $response = $kernel->handle($this->request());

        $this->assertSame('ok', $response->getContent(), 'A failing hook discarded a successful response.');
    }

    public function test_a_failing_hook_is_rethrown_in_debug(): void
    {
        $kernel = $this->kernel(Route::closure(fn () => Response::html('ok')), debug: true);
        $kernel->responseReady(function (): never {
            throw new RuntimeException('hook exploded');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hook exploded');

        $kernel->handle($this->request());
    }
}
