<?php

namespace Nitro\Http\Client;

use Closure;

/**
 * Entry point for outgoing HTTP requests, and the place they can be faked.
 *
 *     Http::get('https://api.example.test/users');
 *
 *     Http::fake(['api.example.test/*' => Http::response(['ok' => true])]);
 *     Http::assertSent(fn ($request) => $request['method'] === 'GET');
 *
 * Every verb and configuration call is forwarded to a fresh
 * {@see PendingRequest}, so nothing configured on one request leaks into the
 * next.
 *
 * @method static Response get(string $url, array $query = [])
 * @method static Response post(string $url, array|string $data = [])
 * @method static Response put(string $url, array|string $data = [])
 * @method static Response patch(string $url, array|string $data = [])
 * @method static Response delete(string $url, array|string $data = [])
 * @method static Response head(string $url, array $query = [])
 * @method static PendingRequest withHeaders(array $headers)
 * @method static PendingRequest withToken(string $token, string $type = 'Bearer')
 * @method static PendingRequest withBasicAuth(string $username, string $password)
 * @method static PendingRequest acceptJson()
 * @method static PendingRequest asForm()
 * @method static PendingRequest asMultipart()
 * @method static PendingRequest baseUrl(string $url)
 * @method static PendingRequest timeout(int $seconds)
 * @method static PendingRequest retry(int $times, int $sleep = 0, ?Closure $when = null)
 * @method static PendingRequest throw(?Closure $callback = null)
 */
class Factory
{
    /**
     * Canned responses while faking, keyed by URL pattern.
     *
     * @var array<string, Closure|Response>|null Null when not faking.
     */
    protected ?array $stubs = null;

    /** @var array<int, array{request: array<string, mixed>, response: Response}> */
    protected array $recorded = [];

    /** Whether a request with no matching stub is an error. */
    protected bool $preventStrayRequests = false;

    // ─── Faking ───────────────────────────────────────────

    /**
     * Stop requests leaving the machine and answer them from $stubs.
     *
     * A stub key is a URL pattern where '*' matches any run of characters;
     * '*' alone matches everything. A stub value is a Response or a closure
     * given the request definition.
     *
     * @param array<string, Closure|Response>|Closure|Response|null $stubs
     */
    public function fake(array|Closure|Response|null $stubs = null): static
    {
        $this->recorded = [];

        $this->stubs = match (true) {
            $stubs === null => ['*' => $this->response()],
            $stubs instanceof Closure, $stubs instanceof Response => ['*' => $stubs],
            default => $stubs,
        };

        return $this;
    }

    /** Let requests leave the machine again. */
    public function stopFaking(): static
    {
        $this->stubs = null;
        $this->recorded = [];

        return $this;
    }

    public function isFaking(): bool
    {
        return $this->stubs !== null;
    }

    /** Fail rather than send a request no stub matched. */
    public function preventStrayRequests(bool $prevent = true): static
    {
        $this->preventStrayRequests = $prevent;

        return $this;
    }

    /**
     * Build a canned response for a stub.
     *
     * @param array<mixed>|string|null          $body
     * @param array<string, string|array<int, string>> $headers
     */
    public function response(array|string|null $body = null, int $status = 200, array $headers = []): Response
    {
        if (is_array($body)) {
            $body = json_encode($body);
            $headers['Content-Type'] ??= 'application/json';
        }

        return new Response((string) $body, $status, $this->normaliseHeaders($headers));
    }

    // ─── Assertions ───────────────────────────────────────

    /**
     * Assert a request matching the callback was sent.
     *
     * @param Closure(array<string, mixed>, Response): bool $callback
     * @throws \RuntimeException When nothing matched.
     */
    public function assertSent(Closure $callback): void
    {
        foreach ($this->recorded as $entry) {
            if ($callback($entry['request'], $entry['response'])) {
                return;
            }
        }

        throw new \RuntimeException('No recorded request matched the given callback.');
    }

    /**
     * Assert no request matching the callback was sent.
     *
     * @param Closure(array<string, mixed>, Response): bool $callback
     * @throws \RuntimeException When one matched.
     */
    public function assertNotSent(Closure $callback): void
    {
        foreach ($this->recorded as $entry) {
            if ($callback($entry['request'], $entry['response'])) {
                throw new \RuntimeException('A recorded request matched the given callback.');
            }
        }
    }

    /** @throws \RuntimeException When any request was sent. */
    public function assertNothingSent(): void
    {
        if ($this->recorded !== []) {
            throw new \RuntimeException(
                'Expected no requests, but ' . count($this->recorded) . ' were sent.'
            );
        }
    }

    /** @throws \RuntimeException When the count does not match. */
    public function assertSentCount(int $count): void
    {
        $actual = count($this->recorded);

        if ($actual !== $count) {
            throw new \RuntimeException("Expected {$count} requests, but {$actual} were sent.");
        }
    }

    /** @return array<int, array{request: array<string, mixed>, response: Response}> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    // ─── Sending ──────────────────────────────────────────

    /** A request carrying none of the previous one's configuration. */
    public function createPendingRequest(): PendingRequest
    {
        return new PendingRequest($this);
    }

    /**
     * Send a built request, or answer it from a stub while faking.
     *
     * @param  array<string, mixed> $request
     * @throws ConnectionException When the transport fails, or a stray request is prevented.
     */
    public function dispatch(array $request): Response
    {
        if ($this->stubs !== null) {
            $response = $this->stubFor($request);

            $this->recorded[] = ['request' => $request, 'response' => $response];

            return $response;
        }

        return $this->transport($request);
    }

    /**
     * Find the stub answering a request.
     *
     * @param  array<string, mixed> $request
     * @throws ConnectionException When nothing matched and strays are prevented.
     */
    protected function stubFor(array $request): Response
    {
        foreach ($this->stubs ?? [] as $pattern => $stub) {
            if (! $this->urlMatches($pattern, $request['url'])) {
                continue;
            }

            $response = $stub instanceof Closure ? $stub($request) : $stub;

            return $response instanceof Response ? $response : $this->response($response);
        }

        if ($this->preventStrayRequests) {
            throw new ConnectionException("No stub matched [{$request['url']}] and stray requests are prevented.");
        }

        return $this->response();
    }

    /** Whether a stub pattern matches a URL, with '*' as the wildcard. */
    protected function urlMatches(string $pattern, string $url): bool
    {
        if ($pattern === '*') {
            return true;
        }

        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace('\*', '.*', $pattern);

        return preg_match('#^(https?://)?' . $pattern . '$#i', $url) === 1
            || preg_match('#^' . $pattern . '$#i', $url) === 1;
    }

    /**
     * Perform the request over the network.
     *
     * @param  array<string, mixed> $request
     * @throws ConnectionException When the request never reached the server.
     */
    protected function transport(array $request): Response
    {
        if (! function_exists('curl_init')) {
            throw new ConnectionException('The HTTP client needs ext-curl.');
        }

        $handle = curl_init();
        $headers = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $request['url'],
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $request['timeout'],
            CURLOPT_CONNECTTIMEOUT => $request['connect_timeout'],
            CURLOPT_FOLLOWLOCATION => ($request['options']['redirects'] ?? 5) > 0,
            CURLOPT_MAXREDIRS => $request['options']['redirects'] ?? 5,
            CURLOPT_SSL_VERIFYPEER => $request['options']['verify'] ?? true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($request['headers']),
            CURLOPT_NOBODY => $request['method'] === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        if ($request['body'] !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request['body']);
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $info = curl_getinfo($handle);
        $error = curl_error($handle);

        curl_close($handle);

        if ($body === false) {
            throw new ConnectionException("Request to [{$request['url']}] failed: {$error}");
        }

        return new Response((string) $body, $status, $headers, $info);
    }

    /**
     * @param  array<string, string> $headers
     * @return array<int, string>
     */
    protected function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }

    /**
     * @param  array<string, string|array<int, string>> $headers
     * @return array<string, array<int, string>>
     */
    protected function normaliseHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = is_array($value) ? array_values($value) : [$value];
        }

        return $normalised;
    }

    /**
     * Forward verbs and configuration to a fresh request.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->createPendingRequest()->{$method}(...$arguments);
    }
}
