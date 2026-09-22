<?php

namespace Nitro\Http\Middleware;

use Nitro\Http\Request;
use Nitro\Http\Response;

/**
 * A middleware that rewrites every value in a request before it is read.
 *
 * On its own it changes nothing: subclasses override {@see transform()} and
 * get the walk — query and body, arrays to any depth, with the dotted path of
 * each value — for free.
 *
 * The rewrite happens here rather than at each read so that everything
 * downstream sees one version of the input. A validator checking a field and
 * a model storing it must not disagree about whether it was trimmed.
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
     * Query and body are cleaned separately because the same field arriving
     * in a URL and in a form has to come out the same either way.
     */
    protected function clean(Request $request): void
    {
        $request->replaceQuery($this->cleanArray((array) $request->query()));

        /*
         * A JSON request keeps its decoded payload in the body, so both cases
         * write back through the same accessor — unlike Laravel, where the
         * JSON bag is a separate object.
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
