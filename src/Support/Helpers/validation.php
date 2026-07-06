<?php

if (!function_exists('validate_email')) {
    /**
     * Validate email address
     * 
     * @param string $email
     * @return bool
     */
    function validate_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('validate_url')) {
    /**
     * Validate URL
     * 
     * @param string $url
     * @return bool
     */
    function validate_url(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

if (!function_exists('validate_ip')) {
    /**
     * Validate IP address
     * 
     * @param string $ip
     * @return bool
     */
    function validate_ip(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

if (!function_exists('is_json')) {
    /**
     * Check if string is valid JSON
     * 
     * @param string $string
     * @return bool
     */
    function is_json(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}