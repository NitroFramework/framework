<?php

namespace Nitro\Routing\Concerns;

/**
 * Route pattern compilation for the {@see \Nitro\Routing\Router}.
 *
 * Detects parameter placeholders, extracts their names, and pre-compiles
 * "{param}" patterns into regular expressions so matching at request time is
 * a cheap preg_match rather than repeated parsing.
 *
 * A placeholder matches one non-slash segment by default. Two things narrow or
 * widen that: a per-parameter constraint from where(), and a trailing "?" which
 * makes the segment optional.
 */
trait CompilesRoutePatterns
{
    /** Pre-compiled regex patterns cache */
    protected array $compiledPatterns = [];

    /**
     * Constraints applied to every route, by parameter name.
     *
     * A route's own where() wins over these.
     *
     * @var array<string, string>
     */
    protected array $globalPatterns = [];

    /** What a placeholder matches when nothing constrains it. */
    protected const DEFAULT_SEGMENT = '[^/]+';

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
     *
     * A trailing "?" marks the parameter optional and is not part of its name.
     */
    protected function extractParameterNames(string $pattern): array
    {
        preg_match_all('/\{([^}]+)\}/', $pattern, $matches);

        return array_map(
            static fn (string $name) => rtrim($name, '?'),
            $matches[1] ?? []
        );
    }

    /** Whether a parameter is declared optional, as "{slug?}". */
    protected function parameterIsOptional(string $pattern, string $name): bool
    {
        return str_contains($pattern, '{' . $name . '?}');
    }

    /**
     * Pre-compile a route pattern into an anchored regex.
     *
     * The pattern is walked rather than substituted wholesale, so literal text
     * can be quoted and placeholders expanded separately. A single pass of
     * preg_replace would leave regex metacharacters live in the literal parts,
     * and a route declared as "/files/report.pdf" would match
     * "/files/reportXpdf".
     *
     * Each placeholder becomes a capture group: the constraint for that
     * parameter if one is set, otherwise a single non-slash segment. A
     * constraint is authored as a fragment and is wrapped, so an alternation
     * such as "foo|bar" cannot escape its group and swallow the rest of the
     * path. A parameter marked optional takes its preceding slash into the
     * optional group, so "/posts/{page?}" matches "/posts" and "/posts/2" both.
     *
     * @param array<string, string> $wheres Per-parameter constraints.
     */
    protected function compilePattern(string $pattern, array $wheres = []): string
    {
        $wheres = array_merge($this->globalPatterns, $wheres);

        $regex = '';
        $offset = 0;

        preg_match_all('#\{([^}]+)\}#', $pattern, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => [$placeholder, $position]) {
            $literal = substr($pattern, $offset, $position - $offset);
            $raw = $matches[1][$index][0];

            $optional = str_ends_with($raw, '?');
            $name = rtrim($raw, '?');

            $group = '(' . ($wheres[$name] ?? self::DEFAULT_SEGMENT) . ')';

            if ($optional && str_ends_with($literal, '/')) {
                $regex .= preg_quote(substr($literal, 0, -1), '#') . '(?:/' . $group . ')?';
            } elseif ($optional) {
                $regex .= preg_quote($literal, '#') . '(?:' . $group . ')?';
            } else {
                $regex .= preg_quote($literal, '#') . $group;
            }

            $offset = $position + strlen($placeholder);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        return '#^' . $regex . '$#';
    }

    /**
     * Match a pattern against a path on the fly, returning captured parameters
     * or false. Retained for backward compatibility with callers that match
     * without pre-compilation.
     */
    protected function matchRoute(string $pattern, string $path)
    {
        if (preg_match($this->compilePattern($pattern), $path, $matches)) {
            array_shift($matches);
            return $matches;
        }

        return false;
    }
}
