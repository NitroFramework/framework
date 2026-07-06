<?php

if (!function_exists('app_path')) {
    /**
     * Get the path to the application (app/) directory
     * 
     * @param string $path
     * @return string
     */
    function app_path(string $path = ''): string
    {
        return base_path('app' . ($path ? '/' . $path : ''));
    }
}

if (!function_exists('base_path')) {
    /**
     * Get the path to the application base directory
     * 
     * @param string $path
     * @return string
     */
    function base_path(string $path = ''): string
    {
        return app()->paths()->base($path);
    }
}

if (!function_exists('config_path')) {
    /**
     * Get the path to the config directory
     * 
     * @param string $path
     * @return string
     */
    function config_path(string $path = ''): string
    {
        return app()->paths()->config($path);
    }
}

if (!function_exists('public_path')) {
    /**
     * Get the path to the public directory
     * 
     * @param string $path
     * @return string
     */
    function public_path(string $path = ''): string
    {
        return app()->publicPath($path);
    }
}

if (!function_exists('resource_path')) {
    /**
     * Get the path to the resources directory
     * 
     * @param string $path
     * @return string
     */
    function resource_path(string $path = ''): string
    {
        return app()->paths()->resources($path);
    }
}

if (!function_exists('view_path')) {
    /**
     * Get the path to the views directory
     * 
     * @param string $path
     * @return string
     */
    function view_path(string $path = ''): string
    {
        return app()->paths()->views($path);
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the path to the storage directory
     * 
     * @param string $path
     * @return string
     */
    function storage_path(string $path = ''): string
    {
        return app()->paths()->storage($path);
    }
}

if (!function_exists('cache_path')) {
    /**
     * Get the path to the cache directory
     * 
     * @param string $path
     * @return string
     */
    function cache_path(string $path = ''): string
    {
        return app()->paths()->cache($path);
    }
}

if (!function_exists('database_path')) {
    /**
     * Get the path to the database directory
     * 
     * @param string $path
     * @return string
     */
    function database_path(string $path = ''): string
    {
        return app()->databasePath($path);
    }
}

if (!function_exists('migrations_path')) {
    /**
     * Get the path to the migrations directory
     * 
     * @param string $path
     * @return string
     */
    function migrations_path(string $path = ''): string
    {
        return app()->paths()->migrations($path);
    }
}
