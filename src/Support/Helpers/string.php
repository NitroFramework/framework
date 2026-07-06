<?php

if (!function_exists('str_contains')) {
    /**
     * Check if string contains substring (PHP 8 polyfill)
     * 
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    /**
     * Check if string starts with substring (PHP 8 polyfill)
     * 
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function str_starts_with(string $haystack, string $needle): bool
    {
        return (string) $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * Check if string ends with substring (PHP 8 polyfill)
     * 
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle !== '' && substr($haystack, -strlen($needle)) === (string) $needle;
    }
}

if (!function_exists('str_slug')) {
    /**
     * Generate a URL-friendly slug
     * 
     * @param string $title
     * @param string $separator
     * @return string
     */
    function str_slug(string $title, string $separator = '-'): string
    {
        // Convert to lowercase
        $slug = mb_strtolower($title, 'UTF-8');

        // Replace non-alphanumeric characters with separator
        $slug = preg_replace('/[^a-z0-9]+/i', $separator, $slug);

        // Remove leading/trailing separators
        $slug = trim($slug, $separator);

        return $slug;
    }
}

if (!function_exists('str_limit')) {
    /**
     * Limit string length
     * 
     * @param string $value
     * @param int $limit
     * @param string $end
     * @return string
     */
    function str_limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit, 'UTF-8')) . $end;
    }
}


if (!function_exists('class_basename')) {
    /**
     * Get the class "basename" of the given object / class.
     *
     * @param string|object $class
     * @return string
     */
    function class_basename($class)
    {
        $class = is_object($class) ? get_class($class) : $class;
        
        return basename(str_replace('\\', '/', $class));
    }
}