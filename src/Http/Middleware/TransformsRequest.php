<?php

namespace Nitro\Http\Middleware;

use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * A middleware that rewrites every value in a request before it is read.
 *
 * Subclasses override {@see transform()} and get the walk — query and
 * body, arrays to any depth, with each value's dotted path.
 */
class TransformsRequest
{
    public function handle(Request $request, callable $next): Response
    {
        $this->clean($request);

        return $next($request);
    }

    /**
     * Rewrite the request's input in place.
     *
     * Query and body are cleaned separately, so the same field comes out
     * the same from a URL or a form.
     */
    protected function clean(Request $request): void
    {
        $request->replaceQuery($this->cleanArray((array) $request->query()));

        /*
         * A JSON request keeps its decoded payload in the body, so both
         * cases write back through the same accessor.
         */
        $request->replace($this->cleanArray((array) $request->post()));
    }

    /**
     * @param  array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    protected function cleanArray(array $data, string $keyPrefix = ''): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = $this->cleanValue($keyPrefix . $key, $value);
        }

        return $data;
    }

    /**
     * Rewrite one value, descending into arrays so a nested field is named by
     * its full path — 'contact.name', not 'name'.
     */
    protected function cleanValue(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->cleanArray($value, $key . '.');
        }

        return $this->transform($key, $value);
    }

    /** What a subclass overrides. Returns the value unchanged by default. */
    protected function transform(string $key, mixed $value): mixed
    {
        return $value;
    }
}
