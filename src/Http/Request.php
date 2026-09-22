<?php

namespace Nitro\Http;

use ArrayAccess;
use Nitro\Container\Container;
use Nitro\Support\Macroable;

/**
 * HTTP Request abstraction over PHP superglobals.
 *
 * Public API follows Laravel naming conventions: the noun is the method, with no
 * `get`/`set` prefixes (`capture`, `method`, `path`, `header`, `query`, `post`,
 * `input`, `all`, `only`, `except`, `allFiles`, `server`, `ip`, `ajax`, `secure`).
 *
 * Bound with instance() once per request, which on its own reads as no more
 * short-lived than anything else. The Application marks the name request-scoped
 * so capture detection can tell when a singleton has taken one in its
 * constructor and is answering every later request from the first one's input.
 */
class Request implements ArrayAccess
{
    /**
     * Lets a feature layer bolt methods onto the Request without the Http core
     * depending on it — the same seam the HTMX layer uses on the Router.
     */
    use Macroable;

    /** filled()/boolean()/date()/validate() and the rest of the input surface. */
    use Concerns\InteractsWithInput;

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
    /**
     * Query, body and uploaded files together.
     *
     * Files are folded in so validation and $request->all() see a field whether
     * it arrived as input or as an upload. Input wins on a name collision.
     */
    public function all(): array
    {
        $input = array_merge($this->query, $this->body);

        return array_replace_recursive($input, $this->allFiles(), $input);
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

    /**
     * Every uploaded file, as a tree of {@see UploadedFile} instances.
     *
     * Normalized once and memoized: $_FILES arrives pivoted for array inputs
     * and rotating it is not free.
     *
     * @return array<string, mixed>
     */
    public function allFiles(): array
    {
        return $this->normalizedFiles ??= FileBag::normalize($this->files);
    }

    /** @var array<string, mixed>|null */
    protected ?array $normalizedFiles = null;

    /**
     * An uploaded file by name, or $default when absent.
     *
     * Dot notation reaches into array inputs: file('photos.0'),
     * file('docs.cover'). With no key, returns every file.
     *
     * @return UploadedFile|array<mixed>|null
     */
    public function file(?string $key = null, mixed $default = null): mixed
    {
        $files = $this->allFiles();

        if ($key === null) {
            return $files;
        }

        return static::dataGet($files, $key) ?? $default;
    }

    /**
     * Whether a file arrived under $key.
     *
     * Presence, not validity: a field left empty posts UPLOAD_ERR_NO_FILE and
     * is dropped during normalization, and a failed upload carries no path, so
     * neither counts. An upload that arrived but is unusable still does — the
     * genuine-upload check belongs at the point of storage, where
     * {@see UploadedFile::isValid()} guards move() and store().
     */
    public function hasFile(string $key): bool
    {
        foreach ($this->fileList($key) as $file) {
            if ($file instanceof UploadedFile && $file->getPathname() !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, mixed> */
    private function fileList(string $key): array
    {
        $file = $this->file($key);

        if ($file === null) {
            return [];
        }

        return is_array($file) ? $file : [$file];
    }

    /** Resolve a dot-notation path within a nested array. */
    protected static function dataGet(array $target, string $key): mixed
    {
        if (array_key_exists($key, $target)) {
            return $target[$key];
        }

        $value = $target;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
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
        $queryString = $this->queryString();
        return $queryString === '' ? $this->url() : $this->url() . '?' . $queryString;
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

    /**
     * The request path, with the front controller's directory removed so an
     * application installed under a subdirectory routes on the path its routes
     * are declared with.
     *
     * The directory is only stripped when SCRIPT_NAME actually names a front
     * controller. PHP's built-in server sets SCRIPT_NAME to the requested URI
     * for anything that looks like a file, so a request for
     * /nitro/hx-component.js would otherwise have /nitro taken off it and
     * arrive at the router as /hx-component.js.
     */
    protected static function normalizePath(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';

        if (str_ends_with($script, '.php') && $script !== $path) {
            $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');

            if ($directory !== '' && str_starts_with($path, $directory . '/')) {
                $path = substr($path, strlen($directory));
            }
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

    // ─── Matched route ────────────────────────────────────────────────────

    /** @var (\Closure(): mixed)|null */
    protected ?\Closure $routeResolver = null;

    /** @param \Closure(): mixed $resolver */
    public function setRouteResolver(\Closure $resolver): static
    {
        $this->routeResolver = $resolver;
        return $this;
    }

    public function getRouteResolver(): ?\Closure
    {
        return $this->routeResolver;
    }

    /**
     * The matched route, or one of its parameters.
     *
     * Returns null before routing has run — a global middleware sees no route.
     */
    public function route(?string $parameter = null, mixed $default = null): mixed
    {
        $route = $this->routeResolver ? ($this->routeResolver)() : null;

        if ($parameter === null || $route === null) {
            return $route;
        }

        return method_exists($route, 'getParameter')
            ? $route->getParameter($parameter, $default)
            : $default;
    }

    /** Whether the matched route's name matches any of the given patterns. */
    public function routeIs(string ...$patterns): bool
    {
        $route = $this->route();
        $name = ($route !== null && method_exists($route, 'getName')) ? $route->getName() : null;

        if ($name === null) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (static::matchesPattern($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    // ─── Authenticated user ───────────────────────────────────────────────

    /** @var (\Closure(?string): mixed)|null */
    protected ?\Closure $userResolver = null;

    /** @param \Closure(?string): mixed $resolver */
    public function setUserResolver(\Closure $resolver): static
    {
        $this->userResolver = $resolver;
        return $this;
    }

    public function getUserResolver(): ?\Closure
    {
        return $this->userResolver;
    }

    /**
     * The authenticated user.
     *
     * Falls back to the bound guard when no resolver was set, so this works
     * without the HTTP layer having to wire one up.
     */
    public function user(?string $guard = null): mixed
    {
        if ($this->userResolver !== null) {
            return ($this->userResolver)($guard);
        }

        $container = app();

        if (! $container->has('auth')) {
            return null;
        }

        return $container->resolve('auth')->user();
    }

    // ─── Session ──────────────────────────────────────────────────────────

    /** The session store, or null when none is started (console, stateless routes). */
    public function session(): mixed
    {
        return $this->hasSession() ? session() : null;
    }

    public function getSession(): mixed
    {
        return $this->session();
    }

    /** Whether a session is available; false outside an application. */
    public function hasSession(): bool
    {
        return Container::hasInstance() && app()->has('session');
    }

    /**
     * Input flashed by the previous request.
     *
     * With no key, the whole old-input array.
     */
    public function old(?string $key = null, mixed $default = null): mixed
    {
        $old = $this->hasSession() ? (session()->get('_old_input') ?? []) : [];

        if (! is_array($old)) {
            return $default;
        }

        if ($key === null) {
            return $old;
        }

        return static::dataGet($old, $key) ?? $default;
    }

    /** Flash this request's input for the next one. Uploads are not flashed. */
    public function flash(): static
    {
        return $this->flashInput($this->inputWithoutFiles());
    }

    /** @param array<int, string>|string $keys */
    public function flashOnly(array|string $keys): static
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        return $this->flashInput(array_intersect_key(
            $this->inputWithoutFiles(),
            array_flip($keys)
        ));
    }

    /** @param array<int, string>|string $keys */
    public function flashExcept(array|string $keys): static
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        return $this->flashInput(array_diff_key(
            $this->inputWithoutFiles(),
            array_flip($keys)
        ));
    }

    /** Drop any flashed input. */
    public function flush(): static
    {
        return $this->flashInput([]);
    }

    /** @param array<string, mixed> $input */
    protected function flashInput(array $input): static
    {
        if ($this->hasSession()) {
            session()->flash('_old_input', $input);
        }

        return $this;
    }

    /**
     * Query and body without uploaded files.
     *
     * @return array<string, mixed>
     */
    protected function inputWithoutFiles(): array
    {
        return array_merge($this->query, $this->body);
    }

    // ─── URL and path matching ────────────────────────────────────────────

    /** Whether the path matches any pattern. `*` matches any run of characters. */
    public function is(string ...$patterns): bool
    {
        $path = trim($this->decodedPath(), '/');

        foreach ($patterns as $pattern) {
            if (static::matchesPattern($pattern, $path === '' ? '/' : $path)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the full URL matches any pattern. */
    public function fullUrlIs(string ...$patterns): bool
    {
        $url = $this->fullUrl();

        foreach ($patterns as $pattern) {
            if (static::matchesPattern($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    public function decodedPath(): string
    {
        return rawurldecode($this->path());
    }

    /**
     * One segment of the path, 1-indexed as in Laravel: segment(1) of
     * /posts/2/edit is 'posts'.
     */
    public function segment(int $index, ?string $default = null): ?string
    {
        return $this->segments()[$index - 1] ?? $default;
    }

    /** @return array<int, string> */
    public function segments(): array
    {
        return array_values(array_filter(
            explode('/', $this->decodedPath()),
            static fn (string $segment) => $segment !== ''
        ));
    }

    /** Host name without the port. */
    public function host(): string
    {
        return explode(':', $this->httpHost())[0];
    }

    /** Host name with the port, as sent. */
    public function httpHost(): string
    {
        return (string) ($this->server['HTTP_HOST']
            ?? $this->headers['host']
            ?? $this->server['SERVER_NAME']
            ?? 'localhost');
    }

    public function scheme(): string
    {
        return $this->secure() ? 'https' : 'http';
    }

    public function schemeAndHttpHost(): string
    {
        return $this->scheme() . '://' . $this->httpHost();
    }

    /** Base URL with no path. */
    public function root(): string
    {
        return rtrim($this->schemeAndHttpHost(), '/');
    }

    /**
     * The full URL with $query merged into its query string.
     *
     * @param array<string, mixed> $query
     */
    public function fullUrlWithQuery(array $query): string
    {
        $merged = array_merge($this->query, $query);

        return $merged === []
            ? $this->url()
            : $this->url() . '?' . http_build_query($merged);
    }

    /**
     * The full URL with the given query keys removed.
     *
     * @param array<int, string>|string $keys
     */
    public function fullUrlWithoutQuery(array|string $keys): string
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $remaining = array_diff_key($this->query, array_flip($keys));

        return $remaining === []
            ? $this->url()
            : $this->url() . '?' . http_build_query($remaining);
    }

    // ─── Content negotiation ──────────────────────────────────────────────

    /**
     * Accepted content types, best first.
     *
     * Ordered by the q parameter, defaulting to 1.0. Equal quality keeps the
     * order the client sent, which is how preference is signalled at the same
     * weight.
     *
     * @return array<int, string>
     */
    public function getAcceptableContentTypes(): array
    {
        $accept = $this->header('accept');

        if ($accept === null || trim($accept) === '') {
            return [];
        }

        $types = [];

        foreach (explode(',', $accept) as $part) {
            $segments = explode(';', $part);
            $type = strtolower(trim($segments[0]));

            if ($type === '') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $matches)) {
                    $quality = (float) $matches[1];
                }
            }

            $types[] = ['type' => $type, 'q' => $quality];
        }

        usort($types, static fn (array $a, array $b) => $b['q'] <=> $a['q']);

        return array_column($types, 'type');
    }

    /** @param array<int, string>|string $contentTypes */
    public function accepts(array|string $contentTypes): bool
    {
        $contentTypes = is_array($contentTypes) ? $contentTypes : func_get_args();
        $accepts = $this->getAcceptableContentTypes();

        if ($accepts === []) {
            return true;
        }

        foreach ($accepts as $accept) {
            if ($accept === '*/*' || $accept === '*') {
                return true;
            }

            foreach ($contentTypes as $type) {
                if (static::matchesMimeType($accept, $type)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function acceptsJson(): bool
    {
        return $this->accepts('application/json');
    }

    public function acceptsHtml(): bool
    {
        return $this->accepts('text/html');
    }

    public function acceptsAnyContentType(): bool
    {
        $accepts = $this->getAcceptableContentTypes();

        return $accepts === []
            || $accepts[0] === '*/*'
            || $accepts[0] === '*';
    }

    /**
     * Whether the client prefers a JSON response.
     *
     * The most-preferred type must name JSON itself, either as a /json type or
     * a +json suffix. A wildcard accept means the client has no preference,
     * which is not a request for JSON.
     */
    public function wantsJson(): bool
    {
        $accepts = $this->getAcceptableContentTypes();

        if (! isset($accepts[0])) {
            return false;
        }

        $preferred = strtolower($accepts[0]);

        return str_contains($preferred, '/json') || str_contains($preferred, '+json');
    }

    /**
     * The first of $contentTypes the client will accept, or null.
     *
     * @param array<int, string>|string $contentTypes
     */
    public function prefers(array|string $contentTypes): ?string
    {
        $contentTypes = is_array($contentTypes) ? $contentTypes : func_get_args();

        foreach ($this->getAcceptableContentTypes() as $accept) {
            if ($accept === '*/*' || $accept === '*') {
                return $contentTypes[0] ?? null;
            }

            foreach ($contentTypes as $type) {
                if (static::matchesMimeType($accept, $type)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /** A short name for the preferred response format. */
    public function format(string $default = 'html'): string
    {
        foreach ($this->getAcceptableContentTypes() as $type) {
            $format = match (true) {
                static::matchesMimeType($type, 'text/html')        => 'html',
                static::matchesMimeType($type, 'application/json') => 'json',
                static::matchesMimeType($type, 'text/plain')       => 'txt',
                static::matchesMimeType($type, 'application/xml'),
                static::matchesMimeType($type, 'text/xml')         => 'xml',
                default                                            => null,
            };

            if ($format !== null) {
                return $format;
            }
        }

        return $default;
    }

    /** Whether an Accept entry covers a concrete content type. */
    public static function matchesType(string $actual, string $type): bool
    {
        return static::matchesMimeType($actual, $type);
    }

    private static function matchesMimeType(string $accept, string $type): bool
    {
        $accept = strtolower(trim($accept));
        $type = strtolower(trim($type));

        if ($accept === $type || $accept === '*/*' || $accept === '*') {
            return true;
        }

        [$acceptMain] = array_pad(explode('/', $accept, 2), 2, '*');
        [$typeMain, $typeSub] = array_pad(explode('/', $type, 2), 2, '*');
        $acceptSub = explode('/', $accept, 2)[1] ?? '*';

        if ($acceptMain !== $typeMain && $acceptMain !== '*') {
            return false;
        }

        return $acceptSub === '*' || $acceptSub === $typeSub;
    }

    // ─── Headers, client, tokens ──────────────────────────────────────────

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    /** The token from an `Authorization: Bearer …` header. */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization') ?? '';

        if (stripos($header, 'bearer ') === 0) {
            $token = substr($header, 7);

            return trim($token) === '' ? null : trim($token);
        }

        return null;
    }

    public function userAgent(): ?string
    {
        return $this->header('user-agent');
    }

    /**
     * Client addresses, nearest first.
     *
     * Only consults forwarding headers when the request came from a trusted
     * proxy — otherwise a client can name any address it likes.
     *
     * @return array<int, string>
     */
    public function ips(): array
    {
        $remote = $this->server['REMOTE_ADDR'] ?? null;

        if (! $this->isFromTrustedProxy()) {
            return $remote === null ? [] : [$remote];
        }

        $forwarded = $this->header('x-forwarded-for') ?? '';
        $ips = array_values(array_filter(array_map('trim', explode(',', $forwarded))));

        if ($remote !== null) {
            $ips[] = $remote;
        }

        return $ips;
    }

    public function hasCookie(string $key): bool
    {
        return $this->cookie($key) !== null;
    }

    /** Whether this is a pjax request. */
    public function pjax(): bool
    {
        return $this->header('x-pjax') !== null;
    }

    /** Whether the browser is speculatively prefetching. */
    public function prefetch(): bool
    {
        return strcasecmp((string) $this->header('purpose'), 'prefetch') === 0
            || strcasecmp((string) $this->header('sec-purpose'), 'prefetch') === 0
            || strcasecmp((string) $this->header('x-moz'), 'prefetch') === 0;
    }

    /** A stable identifier for this route + client, for rate limiting. */
    public function fingerprint(): string
    {
        return sha1(implode('|', [
            $this->method(),
            $this->root(),
            $this->path(),
            $this->ip() ?? '',
        ]));
    }

    // ─── Input helpers ────────────────────────────────────────────────────

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_merge(array_keys($this->all()), array_keys($this->allFiles()));
    }

    /**
     * Merge values in, keeping any key the request already has.
     *
     * @param array<string, mixed> $input
     */
    public function mergeIfMissing(array $input): static
    {
        return $this->merge(array_diff_key($input, $this->all()));
    }

    /**
     * Replace the body outright.
     *
     * @param array<string, mixed> $input
     */
    public function replace(array $input): static
    {
        $this->body = $input;

        return $this;
    }

    /**
     * Replace the query parameters.
     *
     * The counterpart to {@see replace()}, which reaches the body only. A
     * middleware that rewrites input — trimming it, or turning empty strings
     * into null — has to reach both, or the same value would be cleaned when
     * it arrives in a form and left alone when it arrives in the URL.
     *
     * @param array<string, mixed> $query
     */
    public function replaceQuery(array $query): static
    {
        $this->query = $query;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->all();
    }

    // ─── ArrayAccess ──────────────────────────────────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->all());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->input((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->body[] = $value;
            return;
        }

        $this->body[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->body[(string) $offset], $this->query[(string) $offset]);
    }

    /** Reading an unset property returns the matching input, as Laravel does. */
    public function __get(string $key): mixed
    {
        return $this->input($key);
    }

    public function __isset(string $key): bool
    {
        return $this->input($key) !== null;
    }

    // ─── Pattern matching ─────────────────────────────────────────────────

    /**
     * Glob-style match where `*` stands for any run of characters.
     *
     * Used for both route names (admin.*) and paths (admin/*).
     */
    protected static function matchesPattern(string $pattern, string $value): bool
    {
        if ($pattern === $value) {
            return true;
        }

        if (! str_contains($pattern, '*')) {
            return false;
        }

        $regex = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return (bool) preg_match('#^' . $regex . '\z#u', $value);
    }
}
