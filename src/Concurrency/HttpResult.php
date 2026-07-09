<?php

namespace Nitro\Concurrency;

/**
 * The outcome of one parallel HTTP call made by Concurrency::http().
 *
 * A tiny value object — status, headers, body — with the couple of helpers you
 * actually reach for (ok(), json()). Separate from Nitro\Http\Response, which is
 * the framework's OUTGOING response; this represents an INCOMING result from a
 * fanned-out request.
 */
class HttpResult
{
    /**
     * @param array<string, string> $headers  Lowercased header name => value.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $error = null,
    ) {
    }

    /** True when the request completed with a 2xx status and no transport error. */
    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    /** True when a transport-level error occurred (DNS, connect, timeout, …). */
    public function failed(): bool
    {
        return $this->error !== null;
    }

    /** Decode the body as JSON (associative by default). */
    public function json(bool $associative = true): mixed
    {
        return json_decode($this->body, $associative);
    }

    /** A single response header (case-insensitive), or null. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
