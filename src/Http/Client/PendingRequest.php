<?php

namespace Nitro\Http\Client;

use Closure;

/**
 * Builds and sends one outgoing HTTP request.
 *
 *     Http::withToken($token)
 *         ->timeout(5)
 *         ->retry(3, 100)
 *         ->post('https://api.example.test/orders', ['sku' => 'A1']);
 *
 * Every configuration method returns the request, so a chain reads in the
 * order it happens.
 */
class PendingRequest
{
    /** @var array<string, string> */
    protected array $headers = [];

    /** @var array<string, mixed> */
    protected array $options = [];

    /** How the body is encoded: 'json', 'form' or 'multipart'. */
    protected string $bodyFormat = 'json';

    protected string $baseUrl = '';

    protected int $timeout = 30;

    protected int $connectTimeout = 10;

    protected int $retries = 1;

    protected int $retryDelay = 0;

    /** Returns true when a failed attempt should be retried. */
    protected ?Closure $retryWhen = null;

    protected bool $throwOnFailure = false;

    protected ?Closure $throwCallback = null;

    /** @var array<int, Closure> Run with the request definition before it is sent. */
    protected array $beforeSending = [];

    public function __construct(
        protected Factory $factory,
    ) {}

    // ─── Headers ──────────────────────────────────────────

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    public function withHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function accept(string $contentType): static
    {
        return $this->withHeader('Accept', $contentType);
    }

    public function acceptJson(): static
    {
        return $this->accept('application/json');
    }

    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeader('Authorization', trim($type . ' ' . $token));
    }

    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withHeader(
            'Authorization',
            'Basic ' . base64_encode($username . ':' . $password)
        );
    }

    public function withUserAgent(string $agent): static
    {
        return $this->withHeader('User-Agent', $agent);
    }

    // ─── Body format ──────────────────────────────────────

    /** Send the body as JSON. The default. */
    public function asJson(): static
    {
        $this->bodyFormat = 'json';

        return $this;
    }

    /** Send the body as application/x-www-form-urlencoded. */
    public function asForm(): static
    {
        $this->bodyFormat = 'form';

        return $this;
    }

    /** Send the body as multipart/form-data. */
    public function asMultipart(): static
    {
        $this->bodyFormat = 'multipart';

        return $this;
    }

    // ─── Transport ────────────────────────────────────────

    /** Prefix relative URLs with this base. */
    public function baseUrl(string $url): static
    {
        $this->baseUrl = rtrim($url, '/');

        return $this;
    }

    /** Seconds to wait for the whole request. */
    public function timeout(int $seconds): static
    {
        $this->timeout = max(0, $seconds);

        return $this;
    }

    /** Seconds to wait for the connection alone. */
    public function connectTimeout(int $seconds): static
    {
        $this->connectTimeout = max(0, $seconds);

        return $this;
    }

    /**
     * Attempt the request up to $times, pausing between attempts.
     *
     * @param int          $times      Total attempts, not extra ones.
     * @param int          $sleep      Milliseconds between attempts.
     * @param Closure|null $when       Given the exception or response; retry when it returns true.
     */
    public function retry(int $times, int $sleep = 0, ?Closure $when = null): static
    {
        $this->retries = max(1, $times);
        $this->retryDelay = max(0, $sleep);
        $this->retryWhen = $when;

        return $this;
    }

    /** Do not verify the peer's TLS certificate. */
    public function withoutVerifying(): static
    {
        $this->options['verify'] = false;

        return $this;
    }

    /** Follow redirects, or stop following them. */
    public function withRedirects(bool $follow = true, int $max = 5): static
    {
        $this->options['redirects'] = $follow ? $max : 0;

        return $this;
    }

    /** Raise a RequestException when the response is 4xx or 5xx. */
    public function throw(?Closure $callback = null): static
    {
        $this->throwOnFailure = true;
        $this->throwCallback = $callback;

        return $this;
    }

    /** Inspect or alter the request definition before it is sent. */
    public function beforeSending(Closure $callback): static
    {
        $this->beforeSending[] = $callback;

        return $this;
    }

    // ─── Verbs ────────────────────────────────────────────

    /** @param array<string, mixed> $query */
    public function get(string $url, array $query = []): Response
    {
        return $this->send('GET', $url, ['query' => $query]);
    }

    public function head(string $url, array $query = []): Response
    {
        return $this->send('HEAD', $url, ['query' => $query]);
    }

    /** @param array<string, mixed>|string $data */
    public function post(string $url, array|string $data = []): Response
    {
        return $this->send('POST', $url, ['body' => $data]);
    }

    /** @param array<string, mixed>|string $data */
    public function put(string $url, array|string $data = []): Response
    {
        return $this->send('PUT', $url, ['body' => $data]);
    }

    /** @param array<string, mixed>|string $data */
    public function patch(string $url, array|string $data = []): Response
    {
        return $this->send('PATCH', $url, ['body' => $data]);
    }

    /** @param array<string, mixed>|string $data */
    public function delete(string $url, array|string $data = []): Response
    {
        return $this->send('DELETE', $url, ['body' => $data]);
    }

    /**
     * Build the request, run it through retries, and return the response.
     *
     * @param  array{query?: array<string, mixed>, body?: array<string, mixed>|string} $payload
     * @throws ConnectionException When no attempt reached the server.
     * @throws RequestException    When throw() was asked for and the response failed.
     */
    public function send(string $method, string $url, array $payload = []): Response
    {
        $request = $this->buildRequest($method, $url, $payload);

        foreach ($this->beforeSending as $callback) {
            $callback($request);
        }

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retries) {
            $attempt++;

            try {
                $response = $this->factory->dispatch($request);

                if (! $this->shouldRetry($response, null) || $attempt >= $this->retries) {
                    return $this->finish($response);
                }
            } catch (ConnectionException $exception) {
                $lastException = $exception;

                if (! $this->shouldRetry(null, $exception) || $attempt >= $this->retries) {
                    throw $exception;
                }
            }

            if ($this->retryDelay > 0) {
                usleep($this->retryDelay * 1000);
            }
        }

        throw $lastException ?? new ConnectionException("Request to [{$url}] was never attempted.");
    }

    /**
     * Assemble everything the transport needs into one definition.
     *
     * @param  array{query?: array<string, mixed>, body?: array<string, mixed>|string} $payload
     * @return array<string, mixed>
     */
    protected function buildRequest(string $method, string $url, array $payload): array
    {
        $url = $this->resolveUrl($url);
        $query = $payload['query'] ?? [];

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $body = $payload['body'] ?? null;
        $headers = $this->headers;

        if ($body !== null && $body !== [] && $body !== '') {
            [$body, $contentType] = $this->encodeBody($body);

            if ($contentType !== null && ! $this->hasHeader('Content-Type')) {
                $headers['Content-Type'] = $contentType;
            }
        } else {
            $body = null;
        }

        return [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'data' => $payload['body'] ?? [],
            'query' => $query,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'options' => $this->options,
        ];
    }

    /**
     * Encode the body for the chosen format.
     *
     * @param  array<string, mixed>|string $body
     * @return array{0: string|array<string, mixed>, 1: string|null}
     */
    protected function encodeBody(array|string $body): array
    {
        if (is_string($body)) {
            return [$body, null];
        }

        return match ($this->bodyFormat) {
            'form' => [http_build_query($body), 'application/x-www-form-urlencoded'],
            'multipart' => [$body, null],
            default => [json_encode($body), 'application/json'],
        };
    }

    protected function resolveUrl(string $url): string
    {
        if ($this->baseUrl === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return $this->baseUrl . '/' . ltrim($url, '/');
    }

    protected function hasHeader(string $name): bool
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Whether another attempt should be made. */
    protected function shouldRetry(?Response $response, ?ConnectionException $exception): bool
    {
        if ($this->retries <= 1) {
            return false;
        }

        if ($this->retryWhen !== null) {
            return (bool) ($this->retryWhen)($exception ?? $response);
        }

        return $exception !== null || $response?->serverError() === true;
    }

    /** Apply throw() before handing the response back. */
    protected function finish(Response $response): Response
    {
        if ($this->throwOnFailure) {
            $response->throw($this->throwCallback);
        }

        return $response;
    }
}
