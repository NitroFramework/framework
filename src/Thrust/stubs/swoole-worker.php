<?php

/**
 * Swoole worker entrypoint.
 *
 * Unlike FrankenPHP, Swoole is the server: this script is run directly and does
 * not come back until the server stops. Bootstrap happens once per worker
 * process, and each request is a coroutine inside it — so several requests are
 * in flight at once and the reset between them matters more, not less.
 *
 *   php swoole-worker.php
 *
 * Environment:
 *
 *   SWOOLE_HOST=0.0.0.0     address to bind
 *   SWOOLE_PORT=8000        port to bind
 *   SWOOLE_WORKERS=0        processes; 0 takes one per CPU
 *   APP_ENV_LOADED=1        skip Dotenv where the platform injected env
 *   APP_DEBUG=false         production
 */

declare(strict_types=1);

use Nitro\Foundation\Application;
use Nitro\Thrust\Adapters\SwooleAdapter;
use Nitro\Thrust\Runner;
use Nitro\Thrust\WorkerMode;

$base = dirname(__DIR__);

require $base . '/vendor/autoload.php';

$adapter = new SwooleAdapter(
    host: (string) (getenv('SWOOLE_HOST') ?: '0.0.0.0'),
    port: (int) (getenv('SWOOLE_PORT') ?: 8000),
    workers: (int) (getenv('SWOOLE_WORKERS') ?: 0),
);

if (! $adapter->isAvailable()) {
    fwrite(STDERR, $adapter->unavailableReason() . PHP_EOL);

    exit(1);
}

try {
    (new Runner(new Application($base), $adapter, new WorkerMode()))->run();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Worker failed to start: ' . $exception->getMessage() . PHP_EOL);

    exit(1);
}
