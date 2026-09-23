<?php

namespace Nitro\Thrust\Adapters;

use Closure;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Thrust\Contracts\WorkerAdapter;

/**
 * Worker adapter for the Swoole runtime.
 *
 * Swoole is its own HTTP server rather than a bridge to one, so nothing here
 * goes through the superglobals: a request arrives as a Swoole object and is
 * translated in, and the response is written out rather than echoed. That is
 * the whole difference from FrankenPHP, and the reason the adapter has to own
 * both ends instead of only the loop.
 *
 * Each worker is a process, and by default serves one request at a time, so
 * `worker_num` is the parallelism. That is deliberate: coroutines of one worker
 * share everything, and this framework clears request state between requests
 * rather than keying it per coroutine — concurrent requests would read each
 * other's. See {@see serve()}.
 */
class SwooleAdapter implements WorkerAdapter
{
    /**
     * @param string $host    Address to bind.
     * @param int    $port    Port to bind.
     * @param int    $workers Processes to run; 0 means one per CPU.
     * @param bool   $concurrentRequests Whether a worker may serve several
     *               requests at once. See {@see serve()} before turning it on.
     * @param array<string, mixed> $options Passed to Swoole's server as-is.
     */
    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8000,
        private readonly int $workers = 0,
        private readonly bool $concurrentRequests = false,
        private readonly array $options = [],
    ) {
    }

    public function isAvailable(): bool
    {
        return extension_loaded('swoole') && class_exists(\Swoole\Http\Server::class);
    }

    public function unavailableReason(): string
    {
        return 'Swoole worker mode is not available. ext-swoole is not loaded — '
            . 'it has no Windows build, so use FrankenPHP there, or install it '
            . 'with `pecl install swoole` on Linux or macOS.';
    }

    /**
     * Serve until the runtime stops or the worker asks to.
     *
     * A worker takes one request at a time by default, so parallelism comes
     * from worker_num — the same shape FrankenPHP gives, on another runtime.
     *
     * Turning on $concurrentRequests lets a worker interleave requests at every
     * I/O wait, which is where Swoole's throughput lives and where this
     * framework is not yet safe: the container is shared, so binding the
     * current request overwrites the one another coroutine is still reading,
     * and the reset between requests fires while others are mid-flight. Making
     * it safe means request scope keyed by coroutine rather than cleared
     * between requests — the container and everything that resets with it.
     */
    public function serve(Closure $handle, Closure $after): void
    {
        $server = new \Swoole\Http\Server($this->host, $this->port);

        $server->set($this->options + [
            'worker_num' => $this->workers > 0 ? $this->workers : swoole_cpu_num(),
            'enable_coroutine' => $this->concurrentRequests,
            // Only with concurrency: hooking PHP's I/O is what lets a waiting
            // request yield to another. Without concurrency it would suspend a
            // coroutine nothing else is sharing, for no gain.
            'hook_flags' => $this->concurrentRequests ? SWOOLE_HOOK_ALL : 0,
        ]);

        $server->on('request', function ($swooleRequest, $swooleResponse) use ($handle, $after, $server): void {
            $response = $handle($this->toNitroRequest($swooleRequest));

            if ($response instanceof Response) {
                $this->emit($response, $swooleResponse);
            } else {
                $swooleResponse->end();
            }

            if ($after() === false) {
                // Recycles this worker; the manager starts a replacement, so
                // the server keeps serving while a tired process is retired.
                $server->stop();
            }
        });

        $server->start();
    }

    /**
     * Build a Nitro request from Swoole's, which never sets the superglobals.
     */
    private function toNitroRequest(object $request): Request
    {
        $server = array_change_key_case((array) ($request->server ?? []), CASE_UPPER);
        $headers = (array) ($request->header ?? []);

        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        $body = (array) ($request->post ?? []);

        // A JSON body arrives as a raw string and is nobody's $_POST, but the
        // framework reads a parsed body — so it is parsed here rather than by
        // every controller that accepts JSON.
        if ($body === [] && str_contains((string) ($headers['content-type'] ?? ''), 'json')) {
            $decoded = json_decode((string) $request->getContent(), true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new Request(
            (string) ($server['REQUEST_METHOD'] ?? 'GET'),
            (string) ($request->server['request_uri'] ?? '/'),
            $headers,
            (array) ($request->get ?? []),
            $body,
            (array) ($request->files ?? []),
            $server,
            (array) ($request->cookie ?? []),
        );
    }

    /**
     * Write a Nitro response onto Swoole's, rather than echoing it.
     *
     * send() would write to the process's output, which under Swoole belongs to
     * the server rather than to this request.
     */
    private function emit(Response $response, object $swooleResponse): void
    {
        $swooleResponse->status($response->getStatusCode());

        foreach ($response->headers() as $name => $value) {
            $swooleResponse->header($name, (string) $value);
        }

        foreach ($response->cookies() as $cookie) {
            if (is_array($cookie) && isset($cookie['name'])) {
                $swooleResponse->cookie(
                    $cookie['name'],
                    (string) ($cookie['value'] ?? ''),
                    (int) ($cookie['expires'] ?? 0),
                    (string) ($cookie['path'] ?? '/'),
                    (string) ($cookie['domain'] ?? ''),
                    (bool) ($cookie['secure'] ?? false),
                    (bool) ($cookie['httponly'] ?? true),
                );
            }
        }

        $swooleResponse->end((string) $response->getContent());
    }
}
