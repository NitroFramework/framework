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
     * Split a raw placeholder into the three things it can declare.
     *
     * "{post:slug?}" is a parameter named post, bound by the slug column,
     * and optional. The colon half is the custom route key: without it a
     * model binds by its own {@see \Nitro\Database\Model\Model::getRouteKeyName()}.
     *
     * @return array{name: string, field: string|null, optional: bool}
     */
    protected function parseParameter(string $raw): array
    {
        $optional = str_ends_with($raw, '?');

        if ($optional) {
            $raw = substr($raw, 0, -1);
        }

        $field = null;

        if (str_contains($raw, ':')) {
            [$raw, $field] = explode(':', $raw, 2);
        }

        return ['name' => $raw, 'field' => $field, 'optional' => $optional];
    }

    /**
     * Extract the ordered list of parameter names from a route pattern
     * (e.g. ["id"] for "/users/{id}", ["post"] for "/posts/{post:slug}").
     */
    protected function extractParameterNames(string $pattern): array
    {
        return array_map(
            fn (string $raw): string => $this->parseParameter($raw)['name'],
            $this->rawParameters($pattern),
        );
    }

    /**
     * The custom route key each parameter declares, by parameter name.
     *
     * "/posts/{post:slug}/{comment}" gives ['post' => 'slug'] — a parameter
     * without a colon is absent rather than null, so the map is empty for the
     * overwhelming majority of routes and costs nothing to carry.
     *
     * @return array<string, string>
     */
    protected function extractBindingFields(string $pattern): array
    {
        $fields = [];

        foreach ($this->rawParameters($pattern) as $raw) {
            $parsed = $this->parseParameter($raw);

            if ($parsed['field'] !== null) {
                $fields[$parsed['name']] = $parsed['field'];
            }
        }

        return $fields;
    }

    /** Whether a parameter is declared optional, as "{slug?}" or "{post:slug?}". */
    protected function parameterIsOptional(string $pattern, string $name): bool
    {
        foreach ($this->rawParameters($pattern) as $raw) {
            $parsed = $this->parseParameter($raw);

            if ($parsed['name'] === $name) {
                return $parsed['optional'];
            }
        }

        return false;
    }

    /**
     * The contents of every "{...}" in a pattern, unparsed.
     *
     * @return array<int, string>
     */
    protected function rawParameters(string $pattern): array
    {
        preg_match_all('/\{([^}]+)\}/', $pattern, $matches);

        return $matches[1] ?? [];
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

            ['name' => $name, 'optional' => $optional] = $this->parseParameter($raw);

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
