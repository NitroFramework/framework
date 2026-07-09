<?php

namespace Nitro\Coroutine;

use CurlHandle;
use Fiber;
use Nitro\Concurrency\HttpResult;

/**
 * Non-blocking HTTP for coroutines. Inside a running scheduler, get()/request()
 * SUSPEND only the calling coroutine while the transfer is in flight — the loop
 * drives every coroutine's handle on one shared curl_multi, so many requests
 * overlap on a single thread. This is the async I/O that makes coroutines worth it.
 *
 * Called OUTSIDE a coroutine it degrades gracefully to a plain blocking request, so
 * the same call site works either way. Returns the shared HttpResult value object.
 */
class Http
{
    public static function get(string $url, array $options = []): HttpResult
    {
        return self::request(['method' => 'GET', 'url' => $url] + $options);
    }

    public static function post(string $url, mixed $body = null, array $options = []): HttpResult
    {
        return self::request(['method' => 'POST', 'url' => $url, 'body' => $body] + $options);
    }

    /**
     * @param array{url: string, method?: string, headers?: array, body?: mixed, timeout?: int, follow?: bool} $spec
     */
    public static function request(array $spec): HttpResult
    {
        $handle    = self::makeHandle($spec);
        $scheduler = Scheduler::current();

        // Only suspend if we're actually inside a fiber the scheduler is driving.
        if ($scheduler !== null && Fiber::getCurrent() !== null) {
            $raw = $scheduler->awaitCurl($handle);

            return new HttpResult($raw['status'], $raw['headers'], $raw['body'], $raw['error']);
        }

        // Blocking fallback for use outside a coroutine.
        $raw     = curl_exec($handle);
        $errno   = curl_errno($handle);
        $error   = $errno !== CURLE_OK ? curl_strerror($errno) : null;
        $status  = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $hsize   = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $raw     = (string) $raw;
        // No curl_close(): the handle frees when $handle drops (deprecated no-op on 8.5+).

        return new HttpResult(
            $status,
            self::parseHeaders(substr($raw, 0, $hsize)),
            substr($raw, $hsize),
            $error,
        );
    }

    private static function makeHandle(array $spec): CurlHandle
    {
        $method  = strtoupper($spec['method'] ?? 'GET');
        $headers = $spec['headers'] ?? [];
        $body    = $spec['body'] ?? null;

        if (is_array($body) || is_object($body)) {
            $body = json_encode($body);
            if (! self::hasHeader($headers, 'content-type')) {
                $headers['Content-Type'] = 'application/json';
            }
        }

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL            => (string) ($spec['url'] ?? ''),
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => (int) ($spec['timeout'] ?? 30),
            CURLOPT_FOLLOWLOCATION => (bool) ($spec['follow'] ?? true),
            CURLOPT_HTTPHEADER     => self::flattenHeaders($headers),
        ]);

        // For internal calls over a self-signed/local cert.
        if (! empty($spec['insecure'])) {
            curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 0);
        }

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        return $handle;
    }

    private static function flattenHeaders(array $headers): array
    {
        $list = [];
        foreach ($headers as $name => $value) {
            $list[] = is_int($name) ? (string) $value : "{$name}: {$value}";
        }

        return $list;
    }

    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (is_string($key) && strtolower($key) === $name) {
                return true;
            }
        }

        return false;
    }

    private static function parseHeaders(string $raw): array
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
