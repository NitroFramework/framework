<?php

namespace Nitro\Routing\Events;

/**
 * Payload for route.matched, route.dispatching and route.dispatched.
 *
 * The three follow one request through routing: which route answered it,
 * that its handler is about to run, and that it returned. Fields fill in as
 * the framework learns them — route.matched knows only the request, while by
 * route.dispatched the route's name and kind are settled.
 *
 * $strategy is how the router found it — a static-table hit or a compiled
 * pattern — and is about matching, not about what kind of route it is. A
 * full-page component route reached from the static table reports 'static'.
 */
class RouteEvent
{
    /**
     * @param string               $method     HTTP verb, uppercase.
     * @param string               $path       Request path, without query string.
     * @param string|null          $name       The route's name, when it has one.
     * @param string|null          $type       The route's kind: controller, closure, view, or a layer's own.
     * @param string|null          $strategy   How it was matched: 'static' or 'dynamic'.
     * @param array<string, mixed> $parameters URL parameters bound from the path.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?string $name = null,
        public readonly ?string $type = null,
        public readonly ?string $strategy = null,
        public readonly array $parameters = [],
    ) {}
}
