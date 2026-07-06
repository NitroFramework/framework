<?php

namespace Nitro\Thrust;

use Nitro\Foundation\Application;
use Nitro\Foundation\Http\Kernel;
use Nitro\PerformanceBar\PerformanceMetrics;
use Nitro\Http\Request;
use Nitro\PerformanceBar\PerformanceBar;
use Nitro\Thrust\Adapters\FrankenPhpAdapter;
use Nitro\Events\Concerns\DispatchesEvents;
use Throwable;

/**
 * Drives the FrankenPHP worker request loop.
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

    public function __construct(
        private Application $app,
        private FrankenPhpAdapter $adapter,
        private WorkerMode $config,
    ) {}

    public function run(): void
    {
        if (!$this->adapter->isAvailable()) {
            throw new \RuntimeException(
                'FrankenPHP worker mode is not available. '
                . 'Run via FrankenPHP (`frankenphp run --config Caddyfile`) instead of php-cli.'
            );
        }

        $this->installSignalHandlers();

        // ── ONE-TIME bootstrap ──
        $this->app->bootstrap();
        $container = $this->app->getContainer();
        // Kernel isn't pre-bound by any provider; make() auto-wires it.
        $kernel = $container->make(Kernel::class);

        // Pre-warm services the request path always needs so even the first
        // request after worker boot is hot.
        foreach ($this->config->persistentServices as $service) {
            if ($container->has($service)) {
                try {
                    $container->get($service);
                } catch (Throwable) {
                    // Skip — provider may defer this until a request.
                }
            }
        }

        // Wire the event dispatcher so app code can hook the worker lifecycle.
        // event() short-circuits when nothing is listening, so this is free on
        // the hot path unless a listener is actually registered.
        if ($container->has('events')) {
            $this->setDispatcher($container->get('events'));
        }
        $this->event(ThrustEvents::WORKER_STARTING, ['pid' => getmypid()]);

        // ── Per-request loop ──
        while ($this->adapter->handleRequest(function () use ($kernel) {
            $this->handleRequest($kernel);
        })) {
            $this->requestCount++;
            $this->resetBetweenRequests();

            if ($this->shouldStop || $this->shouldRestart()) {
                break;
            }
        }

        $this->event(ThrustEvents::WORKER_STOPPING, ['requests' => $this->requestCount]);
    }

    /**
     * Handle a single request. Any exception is swallowed and converted to a
     * 500 response so a single bad request can't take down the worker.
     */
    private function handleRequest(Kernel $kernel): void
    {
        try {
            // Reset the timing baseline for THIS request. start() is cheap
            // now (always records, only collects heavy snapshots in debug).
            PerformanceMetrics::start();

            $request = Request::capture();
            $container = $this->app->getContainer();
            $container->instance('request', $request);
            $container->instance(Request::class, $request);

            $this->event(ThrustEvents::REQUEST_RECEIVED, ['request' => $request]);

            $response = $kernel->handle($request);

            // PerformanceBar only runs if explicitly enabled — keep production silent.
            if (PerformanceBar::isAvailable()) {
                try {
                    PerformanceBar::getInstance()->inject($response);
                } catch (Throwable) {
                    // Never let the bar break the response.
                }
            }

            $response->send();
            $kernel->terminate($request, $response);

            $this->event(ThrustEvents::REQUEST_HANDLED, ['request' => $request, 'response' => $response]);
        } catch (Throwable $e) {
            $this->emitFatalResponse($e);
        }
    }

    /**
     * Last-resort error renderer when the request handler itself throws
     * before Kernel's ExceptionHandler can pick it up.
     */
    private function emitFatalResponse(Throwable $e): void
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
            ? htmlspecialchars($e->getMessage(), ENT_QUOTES) . "\n"
              . htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES)
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
