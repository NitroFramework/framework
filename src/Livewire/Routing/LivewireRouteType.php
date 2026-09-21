<?php

namespace Nitro\Livewire\Routing;

use Closure;
use Nitro\Http\Request;
use Nitro\Http\Response;
use Nitro\Livewire\Runtime\LivewireManager;
use Nitro\Routing\Contracts\RouteType;
use Nitro\Routing\Route;

/**
 * Teaches the router about a full-page component, without the router knowing.
 *
 * This used to be four members on the core Route class, a branch in
 * Router::parseHandler() and an arm in RouteDispatcher's match — so the
 * framework's routing layer named this one, and only this one, of its own
 * feature layers. All of it lives here now, and the router handles a
 * full-page component the same way it would handle anyone else's idea.
 */
class LivewireRouteType implements RouteType
{
    /** @param Closure(): LivewireManager $livewire Built when a route dispatches, not when it registers. */
    public function __construct(
        protected Closure $livewire,
    ) {}

    public function name(): string
    {
        return 'livewire';
    }

    /**
     * Claim the ['livewire' => 'component-name'] handler the `livewire` verb
     * registers, and store the component's name.
     *
     * A name and not a closure, which is the whole point: a closure cannot be
     * serialized, and one closure route turns off route caching for every
     * route in the application, not just this one.
     */
    public function parse(mixed $handler): mixed
    {
        return is_array($handler) && isset($handler['livewire'])
            ? (string) $handler['livewire']
            : null;
    }

    public function dispatch(Route $route, Request $request): Response
    {
        return Response::html(
            ($this->livewire)()->page((string) $route->getHandler())
        );
    }
}
