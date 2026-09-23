<?php

namespace Nitro\Thrust\Adapters;

use Closure;
use Nitro\Http\Request;
use Nitro\Thrust\Contracts\WorkerAdapter;

// Stub for IDE — FrankenPHP provides this at runtime
if (!function_exists('frankenphp_handle_request')) {
    function frankenphp_handle_request(callable $handler): bool { return false; }
}

/**
 * Worker adapter for the FrankenPHP runtime.
 *
 * FrankenPHP populates the superglobals for each request and hands control back
 * through a callback, so the request is captured and the response sent the same
 * way they are under php-fpm. What changes is only that the process survives to
 * do it again.
 */
class FrankenPhpAdapter implements WorkerAdapter
{
    public function isAvailable(): bool
    {
        return function_exists('frankenphp_handle_request');
    }

    public function unavailableReason(): string
    {
        return 'FrankenPHP worker mode is not available. '
            . 'Run via FrankenPHP (`frankenphp run --config Caddyfile`) instead of php-cli.';
    }

    public function serve(Closure $handle, Closure $after): void
    {
        $keepGoing = true;

        while ($keepGoing && frankenphp_handle_request(static function () use ($handle): void {
            $response = $handle(Request::capture());

            $response?->send();
        })) {
            $keepGoing = $after() !== false;
        }
    }

    /**
     * Kept for callers written against the older shape.
     *
     * @deprecated Use {@see serve()}, which owns the loop and works for a
     *             runtime that calls the worker rather than being called by it.
     */
    public function handleRequest(callable $handler): bool
    {
        return frankenphp_handle_request($handler);
    }
}
