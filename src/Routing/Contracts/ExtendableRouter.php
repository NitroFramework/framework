<?php

namespace Nitro\Routing\Contracts;

use Closure;

/**
 * A router that lets a feature layer add its own route-registration verb.
 *
 * Optional, and deliberately separate from {@see RouterInterface}: registering
 * routes is what a router must do, being extensible is not, and an application
 * bringing its own router should not have to implement this to be usable.
 *
 * It exists because both alternatives were worse. A layer installing its verb
 * with `Router::macro(...)` names the framework's concrete router in a static
 * call, which makes that whole layer unusable with any other one. Putting the
 * verb on {@see RouterInterface} is worse still: every router implementer then
 * has to know what that layer is.
 *
 * A layer that wants a verb asks for one, and a router that cannot provide it
 * simply is not asked:
 *
 *     if ($router instanceof ExtendableRouter) {
 *         $router->extend('feed', fn ($path, $name) => $this->get($path, …));
 *     }
 *
 * Without it the layer still works; the application writes the route the long
 * way instead of using the sugar.
 */
interface ExtendableRouter
{
    /**
     * Register a route-registration verb, callable as $router->{$name}(...).
     *
     * The handler is bound to the router instance before it runs, so its body
     * may use $this to reach the router's own registration methods. An
     * implementation that stores the closure unbound will break every handler
     * written against this contract — which is why the binding is stated here
     * rather than left to whatever the first implementation happened to do.
     *
     * @param string  $name    The verb, without parentheses — 'feed' gives $router->feed(…).
     * @param Closure $handler Bound to the router; receives whatever the caller passes.
     */
    public function extend(string $name, Closure $handler): void;
}
