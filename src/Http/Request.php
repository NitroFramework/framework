<?php

namespace Nitro\Http;

use Nitro\Support\Macroable;

/**
 * HTTP Request abstraction over PHP superglobals.
 *
 * Public API follows Laravel naming conventions: the noun is the method, with no
 * `get`/`set` prefixes (`capture`, `method`, `path`, `header`, `query`, `post`,
 * `input`, `all`, `only`, `except`, `allFiles`, `server`, `ip`, `ajax`, `secure`).
 */
class Request
{
    // Lets feature layers (e.g. Validation) bolt methods like validate() onto
    // the Request without the Http core depending on them. See §3 of the source
    // guide — same seam the HTMX layer uses on the Router.
    use Macroable;

    protected string $method;
    protected string $path;
    protected array $headers;
    protected array $query;
    protected array $body;
    protected array $files;
    protected array $server;
    protected array $cookies;

    public function __construct(
        string $method,
        string $path,
        array $headers = [],
        array $query = [],
        array $body = [],
        array $files = [],
        array $server = [],
        array $cookies = []
    ) {
        $this->method  = strtoupper($method);
        $this->path    = $path;
        $this->headers = $headers;
        $this->query   = $query;
        $this->body    = $body;
        $this->files   = $files;
        $this->server  = $server;
        $this->cookies = $cookies;
    }

    // ─── Factory ──────────────────────────────────────────────────────────

    /** Create a Request from PHP superglobals (Laravel-style). */
    public static function capture(): self
    {
        $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path    = static::normalizePath($_SERVER['REQUEST_URI'] ?? '/');
        $headers = static::parseHeaders();

        return new static($method, $path, $headers, $_GET, $_POST, $_FILES, $_SERVER, $_COOKIE);
    }

    /**
     * Read a request cookie (all cookies when no key is given). Cookies are
     * captured once at request build — the rest of the framework asks the
     * Request instead of touching $_COOKIE.
     */
    public function cookie(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->cookies;
        }
        return $this->cookies[$key] ?? $default;
    }

    /** Replace the request cookies (used by EncryptCookies after decrypting). */
    public function setCookies(array $cookies): self
    {
        $this->cookies = $cookies;
        return $this;
    }

    // ─── Primary accessors ────────────────────────────────────────────────

    /** HTTP verb in upper case (GET, POST, …). */
    public function method(): string
    {
        return $this->method;
    }

    /** Request URI path with the script name stripped and trailing slash trimmed. */
    public function path(): string
    {
        return $this->path;
    }

    /** All parsed request headers, keyed by lowercase header name. */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Single header lookup with case-insensitive name. */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Query string parameters. With no argument returns all; with a key
     * returns the matching value or $default.
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    /**
     * POST body parameters. With no argument returns all; with a key returns
     * the matching value or $default.
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    /** Merged input ({post overrides query}) — usually what controllers want. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** All merged input. */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** Only the given keys from the merged input (Laravel's $request->only()). */
    public function only(string ...$keys): array
    {
        $all = $this->all();
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $result[$key] = $all[$key];
            }
        }
        return $result;
    }

    /** Everything except the given keys (Laravel's $request->except()). */
    public function except(string ...$keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /** Uploaded files normalized array. */
    public function allFiles(): array
    {
        return $this->files;
    }

    /**
     * Server variables. With no argument returns all; with a key returns the
     * matching value or $default.
     */
    public function server(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->server;
        }
        return $this->server[$key] ?? $default;
    }

    /** Best-effort client IP, honouring common proxy headers. */
    public function ip(): ?string
    {
        $candidates = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        foreach ($candidates as $key) {
            if (!empty($this->server[$key])) {
                // X-Forwarded-For may contain a comma-separated list.
                $value = explode(',', (string) $this->server[$key])[0];
                return trim($value);
            }
        }
        return null;
    }

    /** Convenience alias for input() — Laravel's Request supports this. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->input($key, $default);
    }

    /** True if any of the named keys exists in either query or body. */
    public function has(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (!isset($this->body[$key]) && !isset($this->query[$key])) {
                return false;
            }
        }
        return $keys !== [];
    }

    /** Is the request method one of the given verbs? Case-insensitive. */
    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->method;
    }

    /** XMLHttpRequest detection (jQuery, fetch with X-Requested-With header). */
    public function ajax(): bool
    {
        return !empty($this->server['HTTP_X_REQUESTED_WITH']) &&
            strtolower((string) $this->server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /** HTMX request detection (carries the HX-Request header). */
    public function isHtmx(): bool
    {
        return !empty($this->header('hx-request'));
    }

    /** True if behind HTTPS (handles common edge proxies via HTTPS=on). */
    public function secure(): bool
    {
        return !empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off';
    }

    /** Full URL including scheme, host, and path (no query string). */
    public function url(): string
    {
        $protocol = $this->secure() ? 'https' : 'http';
        $host     = $this->server['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host . $this->path;
    }

    /** Full URL including query string. */
    public function fullUrl(): string
    {
        $qs = $this->queryString();
        return $qs === '' ? $this->url() : $this->url() . '?' . $qs;
    }

    public function queryString(): string
    {
        return (string) ($this->server['QUERY_STRING'] ?? '');
    }

    /**
     * Merge additional data into the request input. GET requests merge into
     * the query bag; everything else merges into the body bag.
     */
    public function merge(array $data): self
    {
        if ($this->method === 'GET') {
            $this->query = array_merge($this->query, $data);
        } else {
            $this->body = array_merge($this->body, $data);
        }
        return $this;
    }

    // ─── Internal helpers ─────────────────────────────────────────────────

    protected static function normalizePath(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';

        $scriptName = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '/' && strpos($path, $scriptName) === 0) {
            $path = substr($path, strlen($scriptName));
        }

        return rtrim($path, '/') ?: '/';
    }

    protected static function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

}
