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

        return new self($content, $statusCode, [
            'Content-Type' => 'application/json; charset=utf-8'
        ]);
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

    public function __toString(): string
    {
        return $this->content;
    }
}
