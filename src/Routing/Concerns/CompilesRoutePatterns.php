<?php

namespace Nitro\Routing\Concerns;

/**
 * Route pattern compilation for the {@see \Nitro\Routing\Router}.
 *
 * Detects parameter placeholders, extracts their names, and pre-compiles
 * "{param}" patterns into regular expressions so matching at request time is
 * a cheap preg_match rather than repeated parsing.
 */
trait CompilesRoutePatterns
{
    /** Pre-compiled regex patterns cache */
    protected array $compiledPatterns = [];

    /**
     * Determine whether a path contains any "{param}" placeholders, i.e.
     * whether it is a dynamic route.
     */
    protected function hasParameters(string $path): bool
    {
        return str_contains($path, '{');
    }

    /**
     * Extract the ordered list of parameter names from a route pattern
     * (e.g. ["id"] for "/users/{id}").
     */
    protected function extractParameterNames(string $pattern): array
    {
        preg_match_all('/\{([^}]+)\}/', $pattern, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Pre-compile a route pattern into an anchored regex, replacing each
     * placeholder with a non-slash capture group. Called once at registration.
     */
    protected function compilePattern(string $pattern): string
    {
        $regex = preg_replace('/\{([^}]+)\}/', '([^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    /**
     * Match a pattern against a path on the fly, returning captured parameters
     * or false. Retained for backward compatibility with callers that match
     * without pre-compilation.
     */
    protected function matchRoute(string $pattern, string $path)
    {
        $regex = preg_replace('/\{([^}]+)\}/', '([^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            array_shift($matches);
            return $matches;
        }

        return false;
    }
}
