<?php

use Nitro\Session\Contracts\SessionInterface;

if (!function_exists('session')) {
    /**
     * Get or set session data through the bound session store.
     *
     * - session()                  → the store instance
     * - session('key')             → value (or null)
     * - session('key', $default)   → value (or $default)
     * - session(['k' => 'v', ...]) → set pairs, returns true
     *
     * @param string|array|null $key
     * @param mixed $default
     * @return mixed
     */
    function session($key = null, $default = null)
    {
        /** @var SessionInterface $store */
        $store = nitro_session();

        if ($key === null) {
            // Laravel-style: bare session() yields the store for ->method() calls.
            return $store;
        }

        if (is_array($key)) {
            $store->put($key);
            return true;
        }

        if (func_num_args() === 2) {
            $store->put($key, $default);
            return $default;
        }

        return $store->get($key, $default);
    }
}

if (!function_exists('nitro_session')) {
    /**
     * Resolve the request's session store, starting it if the kernel hasn't yet
     * (e.g. when a helper is called outside the normal request lifecycle).
     *
     * @return SessionInterface
     */
    function nitro_session(): SessionInterface
    {
        $store = app('session');
        if (!$store->isStarted()) {
            $store->start();
        }
        return $store;
    }
}

if (!function_exists('session_forget')) {
    /**
     * Remove session data.
     */
    function session_forget(string $key): void
    {
        nitro_session()->forget($key);
    }
}

if (!function_exists('session_flush')) {
    /**
     * Clear all session data.
     */
    function session_flush(): void
    {
        nitro_session()->flush();
    }
}

if (!function_exists('flash')) {
    /**
     * Flash data to the session (available on the next request).
     */
    function flash(string $key, $value): void
    {
        nitro_session()->flash($key, $value);
    }
}

if (!function_exists('old')) {
    /**
     * Get old input data (from the previous request).
     */
    function old(string $key, $default = ''): string
    {
        $input = nitro_session()->get('_old_input', []);
        $value = is_array($input) ? ($input[$key] ?? $default) : $default;
        return (string) $value;
    }
}

if (!function_exists('errors')) {
    /**
     * Get validation errors stored in the session (from the previous request).
     *
     * @param string|null $key Optional field name for a single error.
     * @return array|string
     */
    function errors(?string $key = null)
    {
        $err = nitro_session()->get('errors', []);
        if (!is_array($err)) {
            $err = [];
        }
        if ($key === null) {
            return $err;
        }
        return $err[$key] ?? '';
    }
}
