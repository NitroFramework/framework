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
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

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
     * Generate a URL from a named route
     * 
     * @param string $name Route name
     * @param array $parameters Route parameters
     * @return string
     */
    function route(string $name, array $parameters = []): string
    {
        $router = app('router');

        try {
            return $router->route($name, $parameters);
        } catch (\Exception $e) {
            // In debug mode, show the error
            if (config('app.debug')) {
                return "Route[$name] not found: " . $e->getMessage();
            }

            // In production, just return home
            return url('/');
        }
    }
}

if (!function_exists('current_url')) {
    /**
     * Get the current full URL
     * 
     * @return string
     */
    function current_url(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

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
        return $_SERVER['REQUEST_URI'] ?? '/';
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
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        if (empty($path)) {
            return 'https://' . $host;
        }

        return 'https://' . $host . '/' . ltrim($path, '/');
    }
}
