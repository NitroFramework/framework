<?php

/**
 * File Helper Functions
 * 
 * Provides utility functions for file operations.
 * 
 * @package Nitro\Support
 * @author Zeeshan Ali 
 * @version 1.0
 */

if (!function_exists('file_get')) {
    /**
     * Get file contents with error handling
     * 
     * @param string $path
     * @param mixed $default
     * @return mixed
     */
    function file_get(string $path, $default = null)
    {
        if (!file_exists($path)) {
            return $default;
        }

        $contents = file_get_contents($path);
        return $contents !== false ? $contents : $default;
    }
}

if (!function_exists('file_put')) {
    /**
     * Put contents to file with error handling
     * 
     * @param string $path
     * @param string $contents
     * @param bool $append
     * @return bool
     */
    function file_put(string $path, string $contents, bool $append = false): bool
    {
        $flags = $append ? FILE_APPEND | LOCK_EX : LOCK_EX;
        return file_put_contents($path, $contents, $flags) !== false;
    }
}

if (!function_exists('file_size')) {
    /**
     * Get human readable file size
     * 
     * @param string $path
     * @return string|null
     */
    function file_size(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $size = filesize($path);
        return $size !== false ? format_bytes($size) : null;
    }
}
