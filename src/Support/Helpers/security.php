<?php

if (!function_exists('escape')) {
    /**
     * Escape HTML special characters
     * 
     * @param string $value
     * @return string
     */
    function escape(string $value): string
    {
        // ENT_SUBSTITUTE so invalid UTF-8 yields the replacement char rather
        // than an empty string; double-encoding stays on (htmlspecialchars
        // default) — matches nitro_e() and Laravel's e().
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('e')) {
    /**
     * Alias for escape() function
     * 
     * @param string $value
     * @return string
     */
    function e(string $value): string
    {
        return escape($value);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * The current session's CSRF token, or an empty string when there is no
     * session.
     *
     * A read, never a write. The token is minted by the session itself the
     * moment it starts, so by the time a route in the session group renders a
     * form the token is already there. Minting here instead meant that asking
     * for a token created a session: a stateless JSON route, or a view compiler
     * warming itself at construction, would take an id, set a cookie and leave
     * a file behind for a request that had no state to keep.
     *
     * An empty string is the honest answer for a route with no session. A form
     * on such a route could not be verified against one anyway.
     */
    function csrf_token(): string
    {
        try {
            $session = nitro_session();
        } catch (\Throwable) {
            return '';
        }

        if (! $session->isStarted()) {
            return '';
        }

        $token = $session->get('_csrf');

        return is_string($token) ? $token : '';
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate CSRF hidden field
     * 
     * @return string
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . escape(csrf_token()) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    /**
     * Verify CSRF token
     * 
     * @param string|null $token
     * @return bool
     */
    function verify_csrf(?string $token = null): bool
    {
        $token = $token ?? (post('_token') ?: request('_token'));
        return $token && hash_equals(csrf_token(), $token);
    }
}