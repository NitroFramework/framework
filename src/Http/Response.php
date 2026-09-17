<?php

namespace Nitro\Http;

/**
 * HTTP response: body, status code, and headers, plus static factories for the
 * common response types. Method names follow Laravel/Symfony (getContent,
 * setContent, getStatusCode, setStatusCode, header, withHeaders).
 */
class Response
{
    protected string $content;
    protected int $statusCode;
    protected array $headers;
    /** @var array<int, Cookie> Cookies to emit as Set-Cookie headers. */
    protected array $cookies = [];
    protected ?string $layout = null;
    protected string $section = 'content';
    protected ?string $pendingView = null;
    protected array $pendingData = [];
    protected $viewRenderer = null;

    public function layout(string $layout): self
    {
        $this->layout = $layout;
        return $this;
    }

    public function section(string $section): self
    {
        $this->section = $section;
        return $this;
    }

    /** Defer layout wrapping until send(). */
    public function withViewContext(string $view, array $data, $renderer): self
    {
        $this->pendingView = $view;
        $this->pendingData = $data;
        $this->viewRenderer = $renderer;
        return $this;
    }

    /**
     * HTTP status codes
     */
    const HTTP_OK = 200;
    const HTTP_NOT_FOUND = 404;
    const HTTP_INTERNAL_ERROR = 500;
    const HTTP_REDIRECT = 302;

    public function __construct(string $content = '', int $statusCode = self::HTTP_OK, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    /** Response body. */
    public function getContent(): string
    {
        return $this->content;
    }

    /** Replace the response body. */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /** HTTP status code. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Replace the HTTP status code. */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /** Set a header (returns $this) or read one (returns string|null when $value is omitted). */
    public function header(string $name, ?string $value = null): self|string|null
    {
        if ($value === null) {
            return $this->headers[$name] ?? null;
        }
        $this->headers[$name] = $value;
        return $this;
    }

    /** Apply multiple headers and return $this. */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /** Queue a cookie to be sent as a Set-Cookie header. */
    public function withCookie(Cookie $cookie): self
    {
        // Replace any queued cookie with the same name+path.
        foreach ($this->cookies as $i => $existing) {
            if ($existing->name === $cookie->name && $existing->path === $cookie->path) {
                $this->cookies[$i] = $cookie;
                return $this;
            }
        }

        $this->cookies[] = $cookie;
        return $this;
    }

    /** @return array<int, Cookie> Queued cookies. */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** All headers. */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Set the Content-Type header. */
    public function setContentType(string $contentType): self
    {
        return $this->header('Content-Type', $contentType);
    }

    // ─── HTTP caching ───────────────────────────────────────────────────────────

    /** Mark the response cacheable for $seconds (private by default; public = shared caches). */
    public function cache(int $seconds, bool $public = false): self
    {
        $visibility = $public ? 'public' : 'private';

        return $this->header('Cache-Control', "{$visibility}, max-age={$seconds}");
    }

    /** Forbid caching entirely. */
    public function noCache(): self
    {
        $this->header('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $this->header('Pragma', 'no-cache');
    }

    /** Set Last-Modified from a unix timestamp or DateTimeInterface (validator for conditional GET). */
    public function lastModified(int|\DateTimeInterface $time): self
    {
        $timestamp = $time instanceof \DateTimeInterface ? $time->getTimestamp() : $time;

        return $this->header('Last-Modified', gmdate('D, d M Y H:i:s', $timestamp) . ' GMT');
    }

    /** Set an ETag validator (quoted; weak validators prefixed with W/). */
    public function etag(string $tag, bool $weak = false): self
    {
        return $this->header('ETag', ($weak ? 'W/' : '') . '"' . trim($tag, '"') . '"');
    }

    /** Turn this into a 304 Not Modified with an empty body (answer to a conditional GET). */
    public function notModified(): self
    {
        $this->setStatusCode(304);

        return $this->setContent('');
    }

    public function send(): void
{
    if (headers_sent()) {
        echo $this->content;
        return;
    }

    http_response_code($this->statusCode);

    foreach ($this->headers as $name => $value) {
        header("{$name}: {$value}");
    }

    // Cookies emit as repeated Set-Cookie headers (the header map above can
    // only hold one value per name).
    foreach ($this->cookies as $cookie) {
        header('Set-Cookie: ' . $cookie->toHeader(), false);
    }

    echo $this->content;
}

    /**
     * Create successful response
     */
    public static function ok(string $content = '', array $headers = []): self
    {
        return new self($content, self::HTTP_OK, $headers);
    }

    /**
     * Create JSON response
     */
    public static function json(array $data, int $statusCode = self::HTTP_OK): self
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = new self($content, $statusCode, [
            'Content-Type' => 'application/json; charset=utf-8'
        ]);

        // Keep the array so a caller can read the data back without decoding
        // the body again.
        return $response->setOriginalContent($data);
    }

    /**
     * Create HTML response
     */
    public static function html(string $content, int $statusCode = self::HTTP_OK): self
    {
        return new self($content, $statusCode, [
            'Content-Type' => 'text/html; charset=utf-8'
        ]);
    }

    /**
     * Create a redirect response. Returns a RedirectResponse so callers can
     * chain ->withInput()/->withErrors()/->with() Laravel-style.
     */
    public static function redirect(string $url, int $statusCode = self::HTTP_REDIRECT): RedirectResponse
    {
        return new RedirectResponse($url, $statusCode);
    }

    /**
     * Create 404 Not Found response
     */
    public static function notFound(string $content = 'Not Found'): self
    {
        return new self($content, self::HTTP_NOT_FOUND, [
            'Content-Type' => 'text/html; charset=utf-8'
        ]);
    }

    /**
     * Create 500 Internal Server Error response
     */
    public static function error(string $content = 'Internal Server Error'): self
    {
        return new self($content, self::HTTP_INTERNAL_ERROR, [
            'Content-Type' => 'text/html; charset=utf-8'
        ]);
    }

    /**
     * Create response from exception
     */
    public static function fromException(\Throwable $exception): self
    {
        // In production, you might want to hide the actual error
        $content = $exception->getMessage();

        return self::error($content);
    }

    // ─── Status and content ───────────────────────────────────────────────

    /**
     * Read the status code, or set it and continue the chain.
     *
     * The no-argument form is the reader, which is what a caller inspecting a
     * response reaches for; setStatusCode() remains for the setter-only case.
     */
    public function status(?int $code = null): int|static
    {
        if ($code === null) {
            return $this->statusCode;
        }

        $this->statusCode = $code;

        return $this;
    }

    /** The reason phrase for the current status code. */
    public function statusText(): string
    {
        return self::STATUS_TEXTS[$this->statusCode] ?? 'Unknown Status';
    }

    /** Read the body, or set it and continue the chain. */
    public function content(?string $content = null): string|static
    {
        if ($content === null) {
            return $this->content;
        }

        $this->content = $content;

        return $this;
    }

    /**
     * The value the response was built from, before it became a string.
     *
     * A response made from an array or an object keeps the original here, so a
     * caller — a test, or middleware inspecting a JSON response — can read the
     * data without decoding the body again.
     */
    public function getOriginalContent(): mixed
    {
        return $this->original ?? $this->content;
    }

    /** Record the value this response was built from. */
    public function setOriginalContent(mixed $original): static
    {
        $this->original = $original;

        return $this;
    }

    /** The value this response was built from, when it was not a string. */
    protected mixed $original = null;

    // ─── Status inspection ────────────────────────────────────────────────

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isOk(): bool
    {
        return $this->statusCode === 200;
    }

    public function isRedirect(?string $location = null): bool
    {
        $isRedirect = in_array($this->statusCode, [201, 301, 302, 303, 307, 308], true);

        if (! $isRedirect || $location === null) {
            return $isRedirect;
        }

        return $this->headerValue('Location') === $location;
    }

    /** A header's value, matched without regard to case. */
    protected function headerValue(string $name): ?string
    {
        foreach ($this->headers as $existing => $value) {
            if (strcasecmp((string) $existing, $name) === 0) {
                return (string) $value;
            }
        }

        return null;
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    public function isForbidden(): bool
    {
        return $this->statusCode === 403;
    }

    public function isEmpty(): bool
    {
        return in_array($this->statusCode, [204, 304], true);
    }

    // ─── Headers ──────────────────────────────────────────────────────────

    /** Remove a header, matched without regard to case. */
    public function withoutHeader(string $name): static
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp((string) $existing, $name) === 0) {
                unset($this->headers[$existing]);
            }
        }

        return $this;
    }

    public function hasHeader(string $name): bool
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp((string) $existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    // ─── Cookies ──────────────────────────────────────────────────────────

    /**
     * Attach a cookie.
     *
     * Accepts a built Cookie, or the arguments to make one, so a caller does
     * not have to import the class for the common case.
     */
    public function cookie(Cookie|string $cookie, string $value = '', int $minutes = 0, string $path = '/', ?string $domain = null, bool $secure = false, bool $httpOnly = true, string $sameSite = 'Lax'): static
    {
        if (! $cookie instanceof Cookie) {
            $cookie = new Cookie(
                $cookie,
                $value,
                $minutes === 0 ? 0 : time() + ($minutes * 60),
                $path,
                $domain,
                $secure,
                $httpOnly,
                $sameSite
            );
        }

        return $this->withCookie($cookie);
    }

    /**
     * Attach several cookies at once.
     *
     * @param array<int, Cookie> $cookies
     */
    public function withCookies(array $cookies): static
    {
        foreach ($cookies as $cookie) {
            if ($cookie instanceof Cookie) {
                $this->withCookie($cookie);
            }
        }

        return $this;
    }

    /**
     * Expire a cookie in the browser.
     *
     * Queues it with an expiry in the past rather than dropping it from the
     * list: the browser only forgets a cookie it is told to.
     */
    public function withoutCookie(string $name, string $path = '/', ?string $domain = null): static
    {
        $this->cookies = array_values(array_filter(
            $this->cookies,
            static fn (Cookie $cookie) => ! ($cookie->name === $name && $cookie->path === $path)
        ));

        return $this->withCookie(new Cookie($name, '', time() - 3600, $path, $domain));
    }

    /**
     * Expire several cookies.
     *
     * @param array<int, string> $names
     */
    public function withoutCookies(array $names, string $path = '/', ?string $domain = null): static
    {
        foreach ($names as $name) {
            $this->withoutCookie((string) $name, $path, $domain);
        }

        return $this;
    }

    // ─── Exceptions ───────────────────────────────────────────────────────

    /** The exception this response was produced from, if any. */
    protected ?\Throwable $exception = null;

    /** Record the exception this response represents. */
    public function withException(\Throwable $exception): static
    {
        $this->exception = $exception;

        return $this;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    /**
     * Throw this response, unwinding to the kernel, which sends it as-is.
     *
     * Lets code deep in a call stack answer the request without threading a
     * return value back through every caller.
     */
    public function throwResponse(): never
    {
        throw new Exceptions\HttpResponseException($this);
    }

    /**
     * Reason phrases for the status codes a framework actually emits.
     */
    private const STATUS_TEXTS = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        419 => 'Page Expired',
        422 => 'Unprocessable Content',
        423 => 'Locked',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    public function __toString(): string
    {
        return $this->content;
    }
}
