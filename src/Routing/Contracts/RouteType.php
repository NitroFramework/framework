<?php

namespace Nitro\Routing\Contracts;

use Nitro\Http\Request;
use Nitro\Routing\Route;

/**
 * A kind of route the core router does not know about.
 *
 * The router understands four things: a controller, a closure, a callable and
 * a view. Everything else is somebody else's idea, and a layer that has one
 * implements this instead of being named inside the router.
 *
 * A type is asked two questions at two different times. At registration,
 * {@see parse()} is offered the handler and says whether it is its own. At
 * request time, {@see dispatch()} is handed the matched route and produces the
 * result. In between, the route may have been serialized into the route cache
 * and read back — which is why parse() returns a value to store rather than
 * keeping state, and why that value must survive var_export(). Return a name,
 * an id, a class-string; never a closure, or the application loses route
 * caching for every one of its routes, not just this one.
 *
 * Register the type from a service provider's register():
 *
 *     $this->container->resolve(RouteTypes::class)->add(new MyRouteType($this->container));
 */
interface RouteType
{
    /**
     * The identifier stored on routes of this kind, e.g. 'feed'.
     *
     * It is the key the registry is keyed by, so two types cannot share one.
     */
    public function name(): string;

    /**
     * Claim a handler passed to $router->get() and friends, or decline it.
     *
     * @param  mixed $handler The raw handler as the application wrote it.
     * @return mixed The handler value to store on the route, or null to
     *         decline — in which case the router keeps looking.
     */
    public function parse(mixed $handler): mixed;

    /**
     * Execute a matched route of this type.
     *
     * The stored handler is on the route: {@see Route::getHandler()}.
     *
     * @return mixed Anything the HTTP kernel can turn into a response.
     */
    public function dispatch(Route $route, Request $request): mixed;
}
