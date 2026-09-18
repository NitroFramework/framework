<?php

namespace Nitro\Http\Client;

use ArrayAccess;
use Nitro\Support\Collection;

/**
 * The result of an outgoing HTTP request.
 *
 *     $response = Http::get('https://api.example.test/users');
 *
 *     $response->ok();          // 2xx
 *     $response->json('data.0.name');
 *     $response->throw();       // raise on 4xx/5xx
 */
class Response implements ArrayAccess
{
    /** Decoded JSON body, resolved once. */
    protected mixed $decoded = null;

    protected bool $decodedResolved = false;

    /**
     * @param string                             $body    Raw response body.
     * @param int                                $status  HTTP status code.
     * @param array<string, array<int, string>>  $headers Header values, keyed lower-case.
     * @param array<string, mixed>               $info    Transfer details from the transport.
     */
    public function __construct(
        protected string $body = '',
        protected int $status = 200,
        protected array $headers = [],
        protected array $info = [],
    ) {}

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Decode the body as JSON.
     *
     * @param string|null $key     Dot path into the decoded body, or null for all of it.
     * @param mixed       $default Returned when the path is absent.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (! $this->decodedResolved) {
            $this->decoded = json_decode($this->body, true);
            $this->decodedResolved = true;
        }

        if (! is_array($this->decoded)) {
            return $key === null ? $this->decoded : $default;
        }

        if ($key === null) {
            return $this->decoded;
        }

        $value = $this->decoded;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /** The decoded body as an object. */
    public function object(): mixed
    {
        return json_decode($this->body);
    }

    /** The decoded body as a collection. */
    public function collect(?string $key = null): Collection
    {
        $value = $this->json($key, []);

        return new Collection(is_array($value) ? $value : [$value]);
    }

    /**
     * The first value of a header, or null.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return array<string, array<int, string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Transfer details from the transport, such as total_time or url. */
    public function info(?string $key = null): mixed
    {
        return $key === null ? $this->info : ($this->info[$key] ?? null);
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function ok(): bool
    {
        return $this->status === 200;
    }

    public function redirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function failed(): bool
    {
        return $this->clientError() || $this->serverError();
    }

    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    public function unauthorized(): bool
    {
        return $this->status === 401;
    }

    public function forbidden(): bool
    {
        return $this->status === 403;
    }

    public function notFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * Raise when the response failed.
     *
     * @param  callable|null $callback Given the response and the exception before it is thrown.
     * @throws RequestException
     */
    public function throw(?callable $callback = null): static
    {
        if (! $this->failed()) {
            return $this;
        }

        $exception = new RequestException($this);

        if ($callback !== null) {
            $callback($this, $exception);
        }

        throw $exception;
    }

    /** Raise when the response failed and the condition holds. */
    public function throwIf(mixed $condition): static
    {
        $condition = is_callable($condition) ? $condition($this) : $condition;

        return $condition ? $this->throw() : $this;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->json($offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->json($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('An HTTP response is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('An HTTP response is read-only.');
    }

    public function __toString(): string
    {
        return $this->body;
    }
}
