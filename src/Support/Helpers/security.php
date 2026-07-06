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
     * Get CSRF token
     * 
     * @return string
     */
    function csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }

        return $_SESSION['_csrf'];
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