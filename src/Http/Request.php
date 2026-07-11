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
        $path    = static::normalizePath($_SERVER['REQUEST_URI'] ?? '/');
        $headers = static::parseHeaders();
        $method  = static::resolveMethod();
        $body    = static::resolveBody($headers);

        return new static($method, $path, $headers, $_GET, $body, $_FILES, $_SERVER, $_COOKIE);
    }

    /**
     * The HTTP method, honouring POST method spoofing — a `_method` form field or
     * an `X-HTTP-Method-Override` header (only PUT/PATCH/DELETE). This is what makes
     * the @method / method_field() form helpers actually take effect server-side.
     */
    private static function resolveMethod(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'POST') {
            return $method;
        }

        $spoofed = $_POST['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
        if ($spoofed !== null) {
            $spoofed = strtoupper((string) $spoofed);
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofed;
            }
        }

        return $method;
    }

    /**
     * The request body bag: form fields for urlencoded/multipart, or the decoded
     * JSON body for `application/json` — so input()/all() see JSON API payloads.
     *
     * @param array<string, string> $headers Lowercase-keyed request headers.
     * @return array<string, mixed>
     */
    private static function resolveBody(array $headers): array
    {
        $contentType = strtolower($headers['content-type'] ?? ($_SERVER['CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, '/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $_POST;
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

    /**
     * Client IP. Forwarded headers (X-Forwarded-For / X-Real-IP / Client-IP) are
     * honoured ONLY when the request arrives from a configured trusted proxy —
     * otherwise a client could spoof its IP (and bypass IP-keyed throttling) just
     * by sending the header. Falls back to REMOTE_ADDR, the one value a client
     * can't forge. See Request::isFromTrustedProxy().
     */
    public function ip(): ?string
    {
        if ($this->isFromTrustedProxy()) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $key) {
                if (!empty($this->server[$key])) {
                    // X-Forwarded-For may be a comma-separated list; the first
                    // entry is the original client.
                    return trim(explode(',', (string) $this->server[$key])[0]);
                }
            }
        }

        return isset($this->server['REMOTE_ADDR']) ? (string) $this->server['REMOTE_ADDR'] : null;
    }

    /**
     * The proxies whose forwarded headers we trust: config('app.trusted_proxies')
     * — an array of exact REMOTE_ADDR values, or '*' to trust all (only safe when
     * the app is reachable solely via a known proxy). Empty means trust nothing.
     *
     * @return array<int, string>|array{0: '*'}
     */
    protected function trustedProxies(): array
    {
        // Resolve defensively: config may be unavailable (early bootstrap, CLI,
        // isolated unit tests). Absent config → trust nothing, the safe default.
        try {
            $proxies = function_exists('config') ? config('app.trusted_proxies', []) : [];
        } catch (\Throwable) {
            $proxies = [];
        }

        if ($proxies === '*' || $proxies === ['*']) {
            return ['*'];
        }

        return is_array($proxies) ? $proxies : [];
    }

    /** Whether this request's immediate peer (REMOTE_ADDR) is a trusted proxy. */
    protected function isFromTrustedProxy(): bool
    {
        $proxies = $this->trustedProxies();

        if ($proxies === []) {
            return false;
        }
        if ($proxies === ['*']) {
            return true;
        }

        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');

        return $remote !== '' && in_array($remote, $proxies, true);
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

    /** Whether the request carries a JSON body (Content-Type: application/json). */
    public function isJson(): bool
    {
        return str_contains(strtolower((string) $this->header('Content-Type')), '/json');
    }

    /**
     * The decoded JSON body, or a single top-level key from it. For a JSON request
     * the body bag already holds the decoded payload, so input()/all() work too.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    /**
     * Whether the client wants a JSON response — an AJAX/fetch call or an Accept
     * header asking for JSON. Used to negotiate JSON vs HTML responses.
     */
    public function expectsJson(): bool
    {
        if ($this->ajax()) {
            return true;
        }

        $accept = strtolower((string) ($this->header('Accept') ?? ''));

        return str_contains($accept, '/json') || str_contains($accept, '+json');
    }

    /**
     * True if the request is over HTTPS. Behind a configured trusted proxy that
     * terminates TLS, X-Forwarded-Proto is honoured so cookies keep their Secure
     * flag and url()/redirects stay on https; otherwise only the real HTTPS
     * server var is trusted (a client can't downgrade/forge the scheme).
     */
    public function secure(): bool
    {
        if ($this->isFromTrustedProxy()) {
            $proto = strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($proto !== '') {
                return $proto === 'https';
            }
        }

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
