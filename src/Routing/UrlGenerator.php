<?php

namespace Nitro\Routing;

use BackedEnum;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\UrlGenerator as BaseUrlGenerator;
use Illuminate\Support\Arr;

use function Illuminate\Support\enum_value;

/**
 * Laravel's URL generator, with route() reading the compiled route table: a named route's URL is
 * built from its URI template (written by route:cache) without building the Route object or
 * running RouteUrlGenerator's parameter matching.
 *
 * Only calls with one possible reading take this path: a route without domain, optional
 * parameters, binding fields or a scheme of its own; exactly its parameters, all positional or
 * all by name; each value a string or an integer once enums and route keys are resolved; no
 * URL::defaults() and no host / path formatters. The steps that remain (root, scheme, fragment,
 * encoding, relative URLs) are RouteUrlGenerator's own. Every other call is Laravel's.
 */
class UrlGenerator extends BaseUrlGenerator
{
    public function route($name, $parameters = [], $absolute = true)
    {
        if (is_string($name) && ($url = $this->compiledRoute($name, $parameters, $absolute)) !== null) {
            return $url;
        }

        return parent::route($name, $parameters, $absolute);
    }

    /**
     * The URL RouteUrlGenerator::to() would build, or null when this call is not one the
     * compiled template covers.
     */
    protected function compiledRoute(string $name, mixed $parameters, bool $absolute): ?string
    {
        if (! $this->routes instanceof CompiledRoutes
            || ($template = $this->routes->urlTemplate($name)) === null
            || $this->formatHostUsing !== null
            || $this->formatPathUsing !== null) {
            return null;
        }

        $generator = $this->routeUrl();

        if ($generator->defaultParameters !== []) {
            return null;
        }

        [$uri, $names] = $template;
        $parameters = Arr::wrap($parameters);

        if (count($parameters) !== count($names)) {
            return null;
        }

        $replace = [];

        foreach ($names as $position => $parameter) {
            if (array_is_list($parameters)) {
                $value = $parameters[$position];
            } elseif (array_key_exists($parameter, $parameters)) {
                $value = $parameters[$parameter];
            } else {
                return null;
            }

            $value = enum_value($value);

            if ($value instanceof UrlRoutable) {
                $value = $value->getRouteKey();
            }

            if (is_int($value)) {
                $value = (string) $value;
            } elseif (! is_string($value) || $value === '' || str_contains($value, '{')) {
                return null;
            } else {
                $value = strtr($value, ['%' => '%25', '?' => '%3F', '#' => '%23']);
            }

            $replace['{'.$parameter.'}'] = $value;
        }

        /** RouteUrlGenerator::replaceRootParameters(), for a route without a domain. */
        $root = $this->formatRoot($this->formatScheme());

        if (str_contains($root, '{')) {
            return null;
        }

        /** UrlGenerator::format() over RouteUrlGenerator::replaceRouteParameters(). */
        $uri = trim(trim($root, '/').'/'.trim(trim(strtr($uri, $replace), '/'), '/'), '/');

        /** RouteUrlGenerator::addQueryString(), with no parameters left for the query. */
        if (! is_null($fragment = parse_url($uri, PHP_URL_FRAGMENT))) {
            $uri = preg_replace('/#.*/', '', $uri)."#{$fragment}";
        }

        /** The rest of RouteUrlGenerator::to(). */
        $uri = strtr(rawurlencode($uri), $generator->dontEncode);

        if (! $absolute) {
            $uri = preg_replace('#^(//|[^/?])+#', '', $uri);

            if ($base = $this->request->getBaseUrl()) {
                $uri = preg_replace('#^'.$base.'#i', '', $uri);
            }

            return '/'.ltrim($uri, '/');
        }

        return $uri;
    }
}
