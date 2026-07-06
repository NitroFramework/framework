<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Http\Request;

/**
 * Controller-side accessors that proxy to the current request (input, has, file,
 * hasFile, method, ajax, isMethod).
 */
trait HandlesRequests
{
    /** Get the current request instance from the container. */
    protected function request(): Request
    {
        return app('request');
    }

    /**
     * Get merged query + body input.
     *  - No args → entire array.
     *  - $key   → that key's value, or $default if absent.
     */
    protected function input(?string $key = null, mixed $default = null): mixed
    {
        $request = $this->request();
        return $key === null ? $request->all() : $request->input($key, $default);
    }

    /** True if the given key exists in either query or body. */
    protected function has(string $key): bool
    {
        return $this->request()->has($key);
    }

    /** True for XHR / fetch-with-X-Requested-With requests. */
    protected function ajax(): bool
    {
        return $this->request()->ajax();
    }

    /** Current HTTP verb in upper case. */
    protected function method(): string
    {
        return $this->request()->method();
    }

    /** True if the current method matches (case-insensitive). */
    protected function isMethod(string $method): bool
    {
        return $this->request()->isMethod($method);
    }

    /** Single uploaded file array or null if not present. */
    protected function file(string $key): ?array
    {
        $files = $this->request()->allFiles();
        return $files[$key] ?? null;
    }

    /** True if the upload succeeded for the given field. */
    protected function hasFile(string $key): bool
    {
        $files = $this->request()->allFiles();
        return isset($files[$key]) && $files[$key]['error'] === UPLOAD_ERR_OK;
    }
}
