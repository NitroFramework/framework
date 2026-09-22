<?php

namespace Tests\Unit\Inertia;

use Nitro\Container\Container;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Inertia\EncryptHistoryMiddleware;
use Nitro\Inertia\ExceptionResponse;
use Nitro\Inertia\Middleware;
use Nitro\Inertia\ResponseFactory;
use Nitro\Inertia\Support\Header;
use Nitro\Session\ArraySessionHandler;
use Nitro\Session\Store;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Failures rendered as pages of the application.
 *
 * Without this a 500 replaces the running client with a server error
 * document: the application is unmounted, and getting back to it is a full
 * page load. Turning the failure into a component keeps the user inside the
 * app, so what these assert is that the status survives the conversion and
 * that an exception the callback declines is left exactly as it was.
 */
class InertiaExceptionResponseTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();

        $store = new Store('nitro_session', new ArraySessionHandler());
        $store->start();

        $this->container->instance('session', $store);
        $this->container->singleton('inertia', static fn (): ResponseFactory => new ResponseFactory());
        $this->container->alias('inertia', ResponseFactory::class);

        Container::setInstance($this->container);
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    private function request(array $headers = []): Request
    {
        $request = new Request('GET', '/missing');

        $reflection = new \ReflectionProperty(Request::class, 'headers');
        $existing = (array) $reflection->getValue($request);

        $normalised = [];
        foreach ([Header::INERTIA => 'true'] + $headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }

        $reflection->setValue($request, array_merge($existing, $normalised));

        return $request;
    }

    private function failure(int $status = 404): ExceptionResponse
    {
        return new ExceptionResponse(
            new RuntimeException('gone'),
            $this->request(),
            new Response('Not Found', $status),
        );
    }

    /** Declined: the response the handler produced goes out untouched. */
    public function test_an_exception_no_page_was_named_for_is_left_alone(): void
    {
        $response = $this->failure(500)->toResponse();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getContent());
    }

    /** Rendered: an Inertia page, carrying the original status. */
    public function test_an_exception_can_be_rendered_as_a_page(): void
    {
        $response = $this->failure(404)
            ->render('Errors/Show', ['status' => 404])
            ->toResponse();

        $this->assertSame(404, $response->getStatusCode(), 'the page must keep the failure status');

        $page = json_decode($response->getContent(), true);

        $this->assertSame('Errors/Show', $page['component']);
        $this->assertSame(404, $page['props']['status']);
    }

    /** The status is readable, which is how a callback decides what to do. */
    public function test_the_status_is_available_to_the_callback(): void
    {
        $this->assertSame(419, $this->failure(419)->statusCode());
    }

    /** Shared props are off unless asked for, since resolving them can fail again. */
    public function test_shared_props_are_excluded_unless_requested(): void
    {
        $this->factoryWithMiddleware();

        $page = json_decode(
            $this->failure(404)->render('Errors/Show')->toResponse()->getContent(),
            true
        );

        $this->assertArrayNotHasKey('tenant', $page['props']);
    }

    public function test_shared_props_can_be_included(): void
    {
        $middleware = $this->factoryWithMiddleware();

        $page = json_decode(
            $this->failure(404)
                ->render('Errors/Show')
                ->usingMiddleware($middleware)
                ->withSharedData()
                ->toResponse()
                ->getContent(),
            true
        );

        $this->assertSame('acme', $page['props']['tenant']);
    }

    /** The root template can be named for the error page alone. */
    public function test_the_root_view_can_be_overridden(): void
    {
        $response = $this->failure(404)
            ->render('Errors/Show')
            ->rootView('error-shell')
            ->toResponse();

        // An Inertia request answers as JSON, so the root view is not in the
        // body — what matters is that naming it did not break the render.
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Register a middleware class the exception response can read settings
     * from, and return its name.
     *
     * @return class-string<Middleware>
     */
    private function factoryWithMiddleware(): string
    {
        $this->container->bind(ExceptionTestMiddleware::class, static fn (): Middleware => new ExceptionTestMiddleware());

        return ExceptionTestMiddleware::class;
    }

    // --- history encryption -------------------------------------------------

    /**
     * The middleware sets the flag the page then reports.
     *
     * A page holding anything sensitive is otherwise kept in browser history
     * in the clear, which is a copy outside the application's control.
     */
    public function test_history_encryption_middleware_marks_the_page(): void
    {
        $response = (new EncryptHistoryMiddleware())->handle(
            $this->request(),
            fn (Request $request): Response => $this->container
                ->resolve('inertia')
                ->render('Secret')
                ->toResponse($request)
        );

        $this->assertTrue(json_decode($response->getContent(), true)['encryptHistory']);
    }

    /** Untouched pages do not carry the flag at all. */
    public function test_a_page_without_the_middleware_carries_no_flag(): void
    {
        $page = json_decode(
            $this->container->resolve('inertia')->render('Public')->toResponse($this->request())->getContent(),
            true
        );

        $this->assertArrayNotHasKey('encryptHistory', $page);
    }
}

/** An application's Inertia middleware, for the shared-data cases. */
class ExceptionTestMiddleware extends Middleware
{
    public function version(Request $request): ?string
    {
        return 'test-version';
    }

    public function share(Request $request): array
    {
        return ['tenant' => 'acme'];
    }
}
