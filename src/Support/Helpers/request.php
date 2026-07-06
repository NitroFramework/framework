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
        return $_POST[$key] ?? $default;
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
        return $_GET[$key] ?? $default;
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
        if ($key === null) {
            return $_FILES;
        }

        return $_FILES[$key] ?? null;
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
        return isset($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK;
    }
}