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

        $request = $container->createOrResolve('request');

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
     * Uploaded files as {@see \Nitro\Http\UploadedFile} instances.
     *
     * Dot notation reaches into array inputs: files('docs.0').
     *
     * @param string|null $key File input name, or null for all of them.
     * @return \Nitro\Http\UploadedFile|array<mixed>|null
     */
    function files(?string $key = null)
    {
        $request = nitro_current_request();

        if ($request !== null) {
            return $key === null ? $request->allFiles() : $request->file($key);
        }

        // No bound request (console, early boot) — normalize the superglobal.
        $all = \Nitro\Http\FileBag::normalize($_FILES);

        return $key === null ? $all : ($all[$key] ?? null);
    }
}

if (!function_exists('has_file')) {
    /**
     * Whether a file arrived under $key.
     *
     * @param string $key
     * @return bool
     */
    function has_file(string $key): bool
    {
        $request = nitro_current_request();

        if ($request !== null) {
            return $request->hasFile($key);
        }

        $file = files($key);

        return $file instanceof \Nitro\Http\UploadedFile && $file->getPathname() !== '';
    }
}
