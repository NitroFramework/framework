<?php

namespace Nitro\Thrust\FrankenPhp;

/**
 * Inspects and controls a running FrankenPHP server through its Caddy admin
 * API (default localhost:2019), mirroring Laravel Octane. Reload and stop go
 * through the admin endpoint; liveness falls back to a raw process check so
 * `thrust:status` still works when the admin API is disabled.
 */
class ProcessInspector
{
    public function __construct(private ServerStateFile $stateFile) {}

    /** Is a FrankenPHP server currently running for this project? */
    public function serverIsRunning(): bool
    {
        $pid = $this->stateFile->read()['masterProcessId'] ?? null;
        if ($pid === null) {
            return false;
        }

        $config = $this->http('GET', $this->adminConfigUrl());
        if ($config !== null && $config['status'] >= 200 && $config['status'] < 300) {
            return true;
        }

        // Admin API unreachable (or disabled) — fall back to a liveness probe.
        return $this->processIsAlive((int) $pid);
    }

    /** Gracefully reload the workers by re-applying the current config. */
    public function reloadServer(): bool
    {
        $config = $this->http('GET', $this->adminConfigUrl());
        if ($config === null) {
            return false;
        }

        $result = $this->http('PATCH', $this->adminConfigUrl(), $config['body'], [
            'Content-Type: application/json',
            'Cache-Control: must-revalidate',
        ]);

        return $result !== null && $result['status'] >= 200 && $result['status'] < 300;
    }

    /** Stop the server via the admin API, falling back to killing the pid. */
    public function stopServer(): bool
    {
        $result = $this->http('POST', $this->adminUrl() . '/stop');
        if ($result !== null && $result['status'] >= 200 && $result['status'] < 300) {
            return true;
        }

        $pid = $this->stateFile->read()['masterProcessId'] ?? null;

        return $pid !== null && $this->killProcess((int) $pid);
    }

    private function adminUrl(): string
    {
        $state = $this->stateFile->read()['state'] ?? [];

        return 'http://' . ($state['adminHost'] ?? 'localhost') . ':' . ($state['adminPort'] ?? 2019);
    }

    private function adminConfigUrl(): string
    {
        return $this->adminUrl() . '/config/apps/frankenphp';
    }

    /**
     * A tiny stream-based HTTP client for the localhost admin API — keeps the
     * layer dependency-free (no Guzzle). Returns [status, body] or null when
     * the connection can't be made.
     */
    private function http(string $method, string $url, ?string $body = null, array $headers = []): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'timeout' => 2,
            'ignore_errors' => true,
            'header' => $headers === [] ? '' : implode("\r\n", $headers),
            'content' => $body ?? '',
        ]]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $status = 0;
        // $http_response_header is populated in local scope by file_get_contents.
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return ['status' => $status, 'body' => $response];
    }

    private function processIsAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $output = [];
            @exec('tasklist /FI ' . escapeshellarg("PID eq {$pid}") . ' /NH 2>NUL', $output);
            return str_contains(implode('', $output), (string) $pid);
        }

        return false;
    }

    private function killProcess(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            @exec('taskkill /PID ' . (int) $pid . ' /T /F 2>NUL', $_, $status);
            return $status === 0;
        }

        return false;
    }
}
