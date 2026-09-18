<?php

use Nitro\Session\Contracts\Session;

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
        /** @var Session $store */
        $store = nitro_session();

        if ($key === null) {
            // Laravel-style: bare session() yields the store for ->method() calls.
            return $store;
        }

        if (is_array($key)) {
            $store->put($key);
            return true;
        }

        // Every string form is a read, including the two-argument one. Writing
        // a single key here would make session('basket', []) — an ordinary read
        // with a default — empty the basket rather than return one, silently
        // and on every request. Write with session(['key' => $value]) or
        // session()->put(), as the docblock above says.
        return $store->get($key, $default);
    }
}

if (!function_exists('nitro_session')) {
    /**
     * Resolve the request's session store.
     *
     * Reading the store does not start it. Only {@see \Nitro\Session\Middleware\StartSession}
     * does that, so a session exists when the matched route asked for one and
     * never because some helper wanted to look something up. Starting on read
     * meant a stateless JSON route could mint a session — and with it an id, a
     * cookie and a file on disk — merely by calling csrf_token().
     *
     * On a route with no session the store answers empty and discards writes,
     * which is the same thing a session that was never started would do.
     *
     * @return Session
     */
    function nitro_session(): Session
    {
        return app('session');
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
