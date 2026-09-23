<?php

namespace Nitro\Thrust;

use Nitro\Foundation\Application;
use Nitro\Http\Kernel;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Thrust\Contracts\WorkerAdapter;
use Nitro\Events\Concerns\DispatchesEvents;
use Nitro\Support\Logger;
use Throwable;

/**
 * Drives a worker request loop, whichever runtime owns it.
 *
 * Bootstrap runs ONCE; each iteration of handleRequest reuses the warm
 * Application + container + router + view compiler + opcache-loaded
 * service classes. Per-request work is just:
 *
 *   1. Request::capture()
 *   2. Bind it as the current request
 *   3. Kernel::handle($request)
 *   4. Response::send()
 *   5. Reset request-scoped state for the next iteration
 */
class Runner
{
    use DispatchesEvents;

    private int $requestCount = 0;

    /** Set by signal handlers to break the run loop on the next iteration. */
    private bool $shouldStop = false;

    /**
     * FrankenPHP by default, so a worker entrypoint that resolves this from the
     * container still builds: an interface is not instantiable, and every
     * application already has a public/worker.php doing exactly that. Another
     * runtime is passed in — see the Swoole worker stub.
     */
    public function __construct(
        private Application $app,
        private WorkerAdapter $adapter = new Adapters\FrankenPhpAdapter(),
        private WorkerMode $config = new WorkerMode(),
    ) {}

    public function run(): void
    {
        if (!$this->adapter->isAvailable()) {
            throw new \RuntimeException($this->adapter->unavailableReason());
        }

        $this->installSignalHandlers();

        // ── ONE-TIME bootstrap ──
        $this->app->bootstrap();
        $container = $this->app->getContainer();
        // Kernel isn't pre-bound by any provider; make() auto-wires it.
        $kernel = $container->resolve(Kernel::class);

        // Pre-warm services the request path always needs so even the first
        // request after worker boot is hot. A service that cannot be built yet
        // is skipped rather than fatal — some are deferred until a request
        // supplies their dependencies — but the reason is logged, because the
        // same silence otherwise hides a genuinely broken provider and turns it
        // into an unexplained slow first request.
        foreach ($this->config->persistentServices as $service) {
            if (! $container->has($service)) {
                continue;
            }

            try {
                $container->get($service);
            } catch (Throwable $exception) {
                Logger::debug('Skipped pre-warming a service', [
                    'service'   => $service,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        // Wire the event dispatcher so app code can hook the worker lifecycle.
        // event() short-circuits when nothing is listening, so this is free on
        // the hot path unless a listener is actually registered.
        if ($container->has('events')) {
            $this->setDispatcher($container->resolve('events'));
        }
        $this->event(ThrustEvents::WORKER_STARTING, ['pid' => getmypid()]);

        // ── Per-request loop, owned by the runtime ──
        $this->adapter->serve(
            fn (Request $request): ?Response => $this->handleRequest($kernel, $request),
            function (): bool {
                $this->requestCount++;
                $this->resetBetweenRequests();

                return ! ($this->shouldStop || $this->shouldRestart());
            },
        );

        $this->event(ThrustEvents::WORKER_STOPPING, ['requests' => $this->requestCount]);
    }

    /**
     * Handle a single request. Any exception is swallowed and converted to a
     * 500 response so a single bad request can't take down the worker.
     *
     * The response is returned rather than sent: a runtime that owns its own
     * response object has to write to that, and echoing would put the body in
     * the server's output instead of this request's.
     */
    private function handleRequest(Kernel $kernel, Request $request): ?Response
    {
        try {
            $container = $this->app->getContainer();
            $container->instance('request', $request);
            $container->instance(Request::class, $request);

            $this->event(ThrustEvents::REQUEST_RECEIVED, ['request' => $request]);

            $response = $kernel->handle($request);

            $kernel->terminate($request, $response);

            $this->event(ThrustEvents::REQUEST_HANDLED, ['request' => $request, 'response' => $response]);

            return $response;
        } catch (Throwable $exception) {
            $this->emitFatalResponse($exception);

            return null;
        }
    }

    /**
     * Last-resort error renderer when the request handler itself throws
     * before Kernel's ExceptionHandler can pick it up.
     */
    private function emitFatalResponse(Throwable $exception): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        $debug = filter_var(
            $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );
        $detail = $debug
            ? htmlspecialchars($exception->getMessage(), ENT_QUOTES) . "\n"
              . htmlspecialchars($exception->getFile() . ':' . $exception->getLine(), ENT_QUOTES)
            : 'Internal Server Error';
        echo "<pre>{$detail}</pre>";
    }

    /**
     * Register SIGTERM / SIGINT handlers so the worker can finish the current
     * request and shut down cleanly instead of being killed mid-response.
     * pcntl is only available on POSIX systems, so this is a no-op on Windows.
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $stop = function (): void { $this->shouldStop = true; };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        if (defined('SIGQUIT')) {
            pcntl_signal(SIGQUIT, $stop);
        }
    }

    private function resetBetweenRequests(): void
    {
        $this->app->resetForWorkerMode($this->config);

        if ($this->config->gcBetweenRequests) {
            gc_collect_cycles();
        }
    }

    private function shouldRestart(): bool
    {
        return $this->requestCount >= $this->config->maxRequests;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }
}
