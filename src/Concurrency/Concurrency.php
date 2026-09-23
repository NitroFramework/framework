<?php

namespace Nitro\Concurrency;

use Nitro\Concurrency\Contracts\Driver;
use Nitro\Concurrency\Drivers\CoroutineDriver;
use Nitro\Concurrency\Drivers\ForkDriver;
use Nitro\Concurrency\Drivers\ProcessDriver;
use Nitro\Concurrency\Drivers\SyncDriver;

/**
 * Per-request task fan-out — run a handful of INDEPENDENT operations at once so a
 * request that does several slow things waits max(them) instead of sum(them).
 *
 * This parallelises tasks WITHIN one request. It does not make the server handle
 * more requests at once: that asks every request to share a process, and asks
 * every service holding request state to be keyed per coroutine rather than reset
 * between requests — a different model, and not this one.
 *
 * Two entry points:
 *   - http()  — parallel HTTP via curl_multi, IN-PROCESS, no subprocess/boot. The
 *               common case ("call N APIs at once") and the one to reach for first.
 *   - run()   — parallel tasks through a driver.
 *
 * The drivers, cheapest first:
 *   - coroutine  a stack and a context each, inside this process. Needs ext-swoole.
 *   - fork       a copy of this process each. Needs ext-pcntl and ext-sockets.
 *   - process    a second framework boot each. Works everywhere.
 *   - sync       one after another. The fallback, and what a suite wants.
 *
 * 'auto' takes the cheapest the platform can actually run, for an application
 * that wants its tasks parallel and does not want a config naming an extension
 * the machine might not have.
 */
class Concurrency
{
    /** @var array<string, Driver> */
    private array $drivers = [];

    public function __construct(private readonly string $defaultDriver = 'process')
    {
    }

    /**
     * Run tasks concurrently and return [key => result]. See TaskInvoker for the
     * accepted task shapes. Uses the configured driver ('process' by default).
     *
     * @param  array<int|string, mixed>  $tasks
     * @return array<int|string, mixed>
     */
    public function run(array $tasks, ?int $timeout = null): array
    {
        return $this->driver()->run($tasks, $timeout);
    }

    /** Resolve a run() driver by name (memoized). */
    public function driver(?string $name = null): Driver
    {
        $name ??= $this->defaultDriver;

        return $this->drivers[$name] ??= match ($name) {
            'process'   => new ProcessDriver(),
            'fork'      => $this->forkDriver(),
            'coroutine' => $this->coroutineDriver(),
            'sync'      => new SyncDriver(),
            'auto'      => $this->bestAvailableDriver(),
            default     => throw new \InvalidArgumentException("Unknown concurrency driver [{$name}]."),
        };
    }

    /**
     * The cheapest driver this platform can actually run.
     *
     * For an application that wants tasks run in parallel without caring how,
     * and does not want its config to be wrong on a machine that lacks an
     * extension. Ordered by what a task costs: a coroutine is a stack, a fork
     * is a copy of the process, a process is a second framework boot.
     */
    private function bestAvailableDriver(): Driver
    {
        return match (true) {
            CoroutineDriver::supported() => new CoroutineDriver(),
            ForkDriver::supported()      => new ForkDriver(),
            default                      => new ProcessDriver(),
        };
    }

    /**
     * The coroutine driver, or a refusal naming what is missing.
     *
     * Checked here for the same reason as the fork driver: an application that
     * never asks for it runs unchanged where ext-swoole does not exist, which
     * is every Windows machine.
     */
    private function coroutineDriver(): Driver
    {
        if (! CoroutineDriver::supported()) {
            throw new \RuntimeException(
                'The coroutine driver needs ext-swoole, which this platform does not have. '
                . "Use 'auto' to take the best this platform has, or the process driver."
            );
        }

        return new CoroutineDriver();
    }

    /**
     * The fork driver, or a refusal naming what is missing.
     *
     * Checked here rather than at construction so an application that never
     * asks for it runs unchanged on a platform without pcntl.
     */
    private function forkDriver(): Driver
    {
        if (! ForkDriver::supported()) {
            throw new \RuntimeException(
                'The fork driver needs ext-pcntl and ext-sockets, which this platform does not have. '
                . 'Use the process driver, or the sync driver in tests.'
            );
        }

        return new ForkDriver();
    }

    /**
     * Fire off tasks in the background and return immediately (fire-and-forget).
     * Best-effort: each serialisable task is spawned as a detached subprocess.
     *
     * @param array<int|string, mixed> $tasks
     */
    public function defer(array $tasks, ?string $driver = null): void
    {
        $this->driver($driver)->defer($tasks);
    }

    /**
     * Run several HTTP requests IN PARALLEL via curl_multi and return [key => HttpResult].
     * This is the lightweight star of the layer: one process, no framework re-boot,
     * wall-time ≈ the slowest request.
     *
     * Each request is a URL string (GET) or a spec:
     *   ['method' => 'POST', 'url' => '...', 'headers' => ['Accept' => 'application/json'],
     *    'body' => [...]|'raw', 'timeout' => 10, 'follow' => true]
     * An array body is JSON-encoded automatically.
     *
     * @param  array<int|string, string|array<string, mixed>>  $requests
     * @param  array<string, mixed>  $defaults  Applied to every request (e.g. shared headers).
     * @return array<int|string, HttpResult>
     */
    public function http(array $requests, array $defaults = []): array
    {
        if (! function_exists('curl_multi_init')) {
            throw new \RuntimeException('Concurrency::http() requires the cURL extension.');
        }

        $multi = curl_multi_init();
        $handles = [];

        foreach ($requests as $key => $request) {
            $spec = $this->normalizeHttp($request, $defaults);
            $curlHandle = curl_init();

            curl_setopt_array($curlHandle, [
                CURLOPT_URL            => $spec['url'],
                CURLOPT_CUSTOMREQUEST  => $spec['method'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => $spec['timeout'],
                CURLOPT_FOLLOWLOCATION => $spec['follow'],
                CURLOPT_HTTPHEADER     => $spec['headers'],
            ]);

            if ($spec['body'] !== null) {
                curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $spec['body']);
            }

            curl_multi_add_handle($multi, $curlHandle);
            $handles[$key] = $curlHandle;
        }

        // Drive all transfers until they complete.
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi);
            }
        } while ($running && $status === CURLM_OK);

        // Per-handle transport results aren't reliably reflected by curl_error()
        // after a multi run — read them from the info queue, keyed by handle id.
        $errno = [];
        while ($info = curl_multi_info_read($multi)) {
            if ($info['result'] !== CURLE_OK) {
                $errno[spl_object_id($info['handle'])] = curl_strerror($info['result']);
            }
        }

        $results = [];
        foreach ($handles as $key => $curlHandle) {
            $error      = $errno[spl_object_id($curlHandle)] ?? null;
            $raw        = (string) curl_multi_getcontent($curlHandle);
            $code       = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($curlHandle, CURLINFO_HEADER_SIZE);

            $results[$key] = new HttpResult(
                $code,
                $this->parseHeaders(substr($raw, 0, $headerSize)),
                substr($raw, $headerSize),
                $error,
            );

            curl_multi_remove_handle($multi, $curlHandle);
            // No curl_close(): handles free when they drop (deprecated no-op on 8.5+).
        }
        // Likewise no curl_multi_close($multi) — $multi frees when this method returns.

        return $results;
    }

    /** Normalise a request (string URL or spec array) into the fields curl needs. */
    private function normalizeHttp(string|array $request, array $defaults): array
    {
        $spec = is_string($request)
            ? array_merge($defaults, ['url' => $request])
            : array_merge($defaults, $request);

        $method  = strtoupper($spec['method'] ?? 'GET');
        $timeout = (int) ($spec['timeout'] ?? 30);
        $follow  = (bool) ($spec['follow'] ?? true);
        $body    = $spec['body'] ?? null;
        $headers = $spec['headers'] ?? [];

        // Array/object body → JSON, and add the header if the caller didn't.
        if (is_array($body) || is_object($body)) {
            $body = json_encode($body);
            if (! $this->hasHeader($headers, 'content-type')) {
                $headers['Content-Type'] = 'application/json';
            }
        }

        return [
            'url'     => (string) ($spec['url'] ?? ''),
            'method'  => $method,
            'timeout' => $timeout,
            'follow'  => $follow,
            'body'    => $body,
            'headers' => $this->flattenHeaders($headers),
        ];
    }

    /** Accept assoc headers (['Accept' => '...']) or a raw list, return curl's list form. */
    private function flattenHeaders(array $headers): array
    {
        $list = [];
        foreach ($headers as $name => $value) {
            $list[] = is_int($name) ? (string) $value : "{$name}: {$value}";
        }

        return $list;
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (is_string($key) && strtolower($key) === $name) {
                return true;
            }
        }

        return false;
    }

    /** Parse a raw header block into [lowercase-name => value] (last block wins on redirects). */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        return $headers;
    }
}
