<?php

if (!function_exists('env')) {
    /**
     * Get environment variable with optional default
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        // Convert string representations to actual types
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        // Remove quotes if present
        if (strlen($value) > 1 && $value[0] === '"' && $value[-1] === '"') {
            return substr($value, 1, -1);
        }

        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value from the config service
     * 
     * @param string|null $key Configuration key (dot notation supported)
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    function config(?string $key = null, $default = null)
    {
        $config = app('config');
        
        // If no key provided, return the entire config instance
        if ($key === null) {
            return $config;
        }
        
        // Get specific config value
        return $config->get($key, $default);
    }
}