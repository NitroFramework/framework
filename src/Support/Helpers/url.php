<?php

if (!function_exists('asset')) {
    /**
     * Generate an asset URL
     *
     * @param string $path Asset path
     * @return string
     */
    function asset(string $path): string
    {
        // Simple version for now - just prepend slash
        // Later you can make this configurable via config('app.asset_url')
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    /**
     * Generate a full URL for the given path
     *
     * @param string $path URL path
     * @return string
     */
    function url(string $path = ''): string
    {
        $request = nitro_current_request();

        if ($request) {
            $scheme = $request->secure() ? 'https' : 'http';
            $host   = (string) $request->server('HTTP_HOST', 'localhost');
        } else {
            $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        }

        if (empty($path)) {
            return $scheme . '://' . $host;
        }

        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . strtoupper($method) . '">';
    }
}

// csrf_token()/csrf_field() are defined canonically in security.php (session
// key '_csrf', CSPRNG-minted). They are intentionally NOT redefined here — a
// second definition reading a different session key ('_token') is a load-order
// landmine that would silently break CSRF verification.

if (!function_exists('route')) {
    /**
     * The URL of a named route.
     *
     * Throws on an unknown name rather than returning a placeholder: the route
     * table is fixed at boot, so a miss is a programming error, not a runtime
     * condition.
     *
     * @param  string  $name  Route name
     * @param  mixed  $parameters  One parameter, or an array of them
     *
     * @throws \InvalidArgumentException when no route has that name.
     */
    function route(string $name, mixed $parameters = []): string
    {
        // Accept a bare parameter as well as an array.
        if (! is_array($parameters)) {
            $parameters = [$parameters];
        }

        // Held between calls: a page of links would otherwise resolve the same
        // singleton once per link. Keyed on the container so a reset (tests,
        // worker teardown) does not hand back a stale router.
        static $router = null;
        static $from = null;

        $container = app();

        if ($router === null || $from !== $container) {
            $from = $container;
            $router = $container->resolve('router');
        }

        return $router->route($name, $parameters);
    }
}

if (!function_exists('current_url')) {
    /**
     * Get the current full URL (scheme, host, path and query string).
     *
     * @return string
     */
    function current_url(): string
    {
        $request = nitro_current_request();

        if ($request) {
            return $request->fullUrl();
        }

        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';

        return $scheme . '://' . $host . $uri;
    }
}

if (!function_exists('current_path')) {
    /**
     * Get the current path (without domain)
     *
     * @return string
     */
    function current_path(): string
    {
        $request = nitro_current_request();

        return $request ? $request->path() : ($_SERVER['REQUEST_URI'] ?? '/');
    }
}

if (!function_exists('secure_url')) {
    /**
     * Generate a secure HTTPS URL
     *
     * @param string $path
     * @return string
     */
    function secure_url(string $path = ''): string
    {
        $request = nitro_current_request();
        $host    = $request
            ? (string) $request->server('HTTP_HOST', 'localhost')
            : ($_SERVER['HTTP_HOST'] ?? 'localhost');

        if (empty($path)) {
            return 'https://' . $host;
        }

        return 'https://' . $host . '/' . ltrim($path, '/');
    }
}
