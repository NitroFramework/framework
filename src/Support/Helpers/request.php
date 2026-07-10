<?php

use Nitro\Http\Request;

if (!function_exists('request')) {
    function request(?string $key = null, $default = null)
    {
        $request = app(Request::class);

        if ($key === null) {
            return $request;
        }

        return $request->get($key, $default);
    }
}

if (!function_exists('nitro_current_request')) {
    /**
     * The bound HTTP Request, or null when none is bound (console, queued jobs,
     * early bootstrap). Input/URL helpers proxy this instead of reading PHP
     * superglobals directly, so they see exactly what the app's Request sees —
     * and stay correct in worker mode where 'request' is rebound per request.
     * Callers fall back to the raw superglobal only when this returns null.
     */
    function nitro_current_request(): ?Request
    {
        $container = app();

        if (!$container->has('request')) {
            return null;
        }

        $request = $container->make('request');

        return $request instanceof Request ? $request : null;
    }
}

if (!function_exists('input')) {
    /**
     * Alias for request() function
     *
     * @param string $key Input key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    function input(string $key, $default = null)
    {
        return request($key, $default);
    }
}

if (!function_exists('post')) {
    /**
     * Get POST input
     *
     * @param string $key Input key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    function post(string $key, $default = null)
    {
        $request = nitro_current_request();

        return $request ? $request->post($key, $default) : ($_POST[$key] ?? $default);
    }
}

if (!function_exists('get')) {
    /**
     * Get GET input
     *
     * @param string $key Input key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    function get(string $key, $default = null)
    {
        $request = nitro_current_request();

        return $request ? $request->query($key, $default) : ($_GET[$key] ?? $default);
    }
}

if (!function_exists('files')) {
    /**
     * Get uploaded files
     *
     * @param string|null $key File input name
     * @return mixed
     */
    function files(?string $key = null)
    {
        $request = nitro_current_request();
        $all = $request ? $request->allFiles() : $_FILES;

        if ($key === null) {
            return $all;
        }

        return $all[$key] ?? null;
    }
}

if (!function_exists('has_file')) {
    /**
     * Check if file was uploaded
     *
     * @param string $key
     * @return bool
     */
    function has_file(string $key): bool
    {
        $file = files($key);

        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }
}
