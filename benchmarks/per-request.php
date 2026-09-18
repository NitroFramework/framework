<?php

declare(strict_types=1);

/**
 * Per-request CPU cost, with the network taken out of the measurement.
 *
 * A requests-per-second figure from a load generator measures the whole
 * machine: the OS network stack, the load tool, the web server, the worker
 * pool, and only then the framework. On a box whose loopback tops out well
 * below the framework's own ceiling, that number says nothing about the
 * framework at all.
 *
 * This boots the application once — as a worker does — and then drives
 * requests straight through the kernel in a loop, timing only the work the
 * framework performs. What comes out is microseconds per request, which is
 * the quantity that decides requests per second per core:
 *
 *     rps per core  =  1_000_000 / microseconds per request
 *
 * Usage:
 *   php benchmarks/per-request.php <app-dir> [--n=20000] [--path=/bench/t0-noop]
 *
 * The app directory is any Nitro application; the path must be a route it
 * serves. Run it against a route with no session, database or view to measure
 * the framework floor, and against a real page to measure a real page.
 */

$args = array_slice($argv, 1);
$appDir = null;
$iterations = 20000;
$path = '/bench/t0-noop';
$method = 'GET';

foreach ($args as $arg) {
    if (str_starts_with($arg, '--n=')) {
        $iterations = max(1, (int) substr($arg, 4));
    } elseif (str_starts_with($arg, '--path=')) {
        $path = substr($arg, 7);
    } elseif (str_starts_with($arg, '--method=')) {
        $method = strtoupper(substr($arg, 9));
    } elseif (! str_starts_with($arg, '--')) {
        $appDir = rtrim($arg, '\\/');
    }
}

if ($appDir === null || ! is_dir($appDir)) {
    fwrite(STDERR, "usage: php benchmarks/per-request.php <app-dir> [--n=20000] [--path=/bench/t0-noop]\n");
    exit(2);
}

$autoload = $appDir . '/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "no vendor/autoload.php under {$appDir}\n");
    exit(2);
}

require $autoload;

use Nitro\Foundation\Application;
use Nitro\Http\Kernel;
use Nitro\Http\Request;

// Debug mode changes error handling and view freshness checks, which is not
// what a production worker runs.
$_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = 'false';
putenv('APP_DEBUG=false');

/**
 * Put the superglobals in the state a real request would leave them, so
 * Request::capture() has something to read.
 */
$prepareGlobals = static function (string $method, string $path): void {
    $_GET = $_POST = $_FILES = $_COOKIE = [];
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PATH_INFO'] = $path;
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['SERVER_NAME'] = '127.0.0.1';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['HTTPS'] = '';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'nitro-bench';
    $_SERVER['HTTP_ACCEPT'] = 'text/html,application/json';
};

$prepareGlobals($method, $path);

// ── One-time bootstrap, exactly as the worker does it ──
$bootStart = hrtime(true);

$app = Application::create($appDir);
$app->bootstrap();
$container = $app->getContainer();
$kernel = $container->createOrResolve(Kernel::class);

$bootMs = (hrtime(true) - $bootStart) / 1e6;

/**
 * One trip through the framework: capture, bind, handle, render.
 *
 * send() is deliberately not called — it writes to the SAPI, which is not
 * the framework's cost and is not available here. The response body is
 * materialised instead, so view rendering is still paid for.
 */
$once = static function () use ($kernel, $container, $prepareGlobals, $method, $path): int {
    $prepareGlobals($method, $path);

    $request = Request::capture();
    $container->instance('request', $request);
    $container->instance(Request::class, $request);

    $response = $kernel->handle($request);

    $body = (string) $response->getContent();

    $kernel->terminate($request, $response);

    return strlen($body);
};

// ── Warm up: first requests pay for lazily-resolved services ──
$warmup = min(500, max(50, (int) ($iterations / 20)));

for ($i = 0; $i < $warmup; $i++) {
    $once();
}

// ── Measure ──
$samples = [];
$bytes = 0;

$memoryBefore = memory_get_usage(true);
$start = hrtime(true);

for ($i = 0; $i < $iterations; $i++) {
    $requestStart = hrtime(true);
    $bytes = $once();
    $samples[] = (hrtime(true) - $requestStart) / 1000.0;
}

$totalMs = (hrtime(true) - $start) / 1e6;
$memoryGrowth = memory_get_usage(true) - $memoryBefore;

sort($samples);

$percentile = static function (array $sorted, float $q): float {
    $index = (int) floor($q * (count($sorted) - 1));

    return $sorted[$index];
};

$mean = array_sum($samples) / count($samples);
$rpsPerCore = 1e6 / $mean;

printf("app            %s\n", $appDir);
printf("route          %s %s\n", $method, $path);
printf("response       %s bytes\n", number_format($bytes));
printf("boot (once)    %.1f ms\n", $bootMs);
printf("requests       %s (after %s warmup)\n", number_format($iterations), number_format($warmup));
printf("wall           %.0f ms\n", $totalMs);
echo "\n";
printf("per request    mean %.1f us | p50 %.1f | p95 %.1f | p99 %.1f | min %.1f\n",
    $mean,
    $percentile($samples, 0.50),
    $percentile($samples, 0.95),
    $percentile($samples, 0.99),
    $samples[0]
);
printf("throughput     %s rps per core (1e6 / mean)\n", number_format($rpsPerCore, 0));
printf("memory growth  %s KB over the run\n", number_format($memoryGrowth / 1024, 1));
echo "\n";
printf("for reference  Hyperf's published 103,921 rps on 8 cores is %s rps per core (%.0f us/request)\n",
    number_format(103921 / 8, 0),
    1e6 / (103921 / 8)
);
