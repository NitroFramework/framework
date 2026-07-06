<?php

namespace Nitro\Thrust\Adapters;

// Stub for IDE — FrankenPHP provides this at runtime
if (!function_exists('frankenphp_handle_request')) {
    function frankenphp_handle_request(callable $handler): bool { return false; }
}

/**
 * Worker adapter for the FrankenPHP runtime.
 */
class FrankenPhpAdapter
{
    public function isAvailable(): bool
    {
        return function_exists('frankenphp_handle_request');
    }

    public function handleRequest(callable $handler): bool
    {
        return frankenphp_handle_request($handler);
    }
}
