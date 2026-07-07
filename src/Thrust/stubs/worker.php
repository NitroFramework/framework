<?php

/**
 * FrankenPHP worker entrypoint.
 *
 * Bootstrap runs ONCE per worker process. The Runner then loops over
 * frankenphp_handle_request, reusing the warm Application + container for
 * every subsequent request. Typical warm-request latency is sub-millisecond.
 *
 * Launch with `php nitro thrust:start` (shells out to the FrankenPHP binary),
 * or directly via:
 *
 *   frankenphp run --config /path/to/Caddyfile
 *
 * Useful env vars (set in your shell or Caddyfile):
 *
 *   FRANKENPHP_CONFIG="worker /var/www/public/worker.php"
 *   APP_ENV_LOADED=1   # skip Dotenv if the platform already injected env
 *   APP_DEBUG=false    # production
 */

declare(strict_types=1);

use Nitro\Foundation\Application;
use Nitro\Thrust\Adapters\FrankenPhpAdapter;
use Nitro\Thrust\WorkerMode;
use Nitro\Thrust\Runner;

// Top-level safety net for truly catastrophic bootstrap failures. Per-request
// exceptions are caught inside Runner::handleRequest.
set_exception_handler(static function (\Throwable $e): void {
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    $message = "FATAL: {$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}\n";

    if (defined('STDERR')) {
        fwrite(STDERR, $message);
    } else {
        error_log($message);
    }

    exit(1);
});

require_once __DIR__ . '/../vendor/autoload.php';

// ── Build the application ONCE ──
// create() records the timing baseline and installs a (stderr, in CLI) fatal
// handler; the one-time bootstrap() inside Runner::run() sets the session
// save-path and enables the profiler when APP_DEBUG is on. No manual wiring here.
$app = Application::create(dirname(__DIR__));

// ── Hand off to the worker loop ──
$container = $app->getContainer();
$container->instance(WorkerMode::class, new WorkerMode());
$container->instance(FrankenPhpAdapter::class, new FrankenPhpAdapter());

$runner = $container->make(Runner::class);
$runner->run();
