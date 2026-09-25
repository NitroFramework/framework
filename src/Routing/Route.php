<?php

namespace Nitro\Routing;

use Illuminate\Routing\Route as BaseRoute;

/**
 * An Illuminate route that can receive parameters matched by Nitro's compiled matcher,
 * so the Symfony route compiler never runs on a request.
 */
class Route extends BaseRoute
{
    /**
     * Compiled-route index inside CompiledRoutes (null for routes added at runtime).
     */
    public ?int $nitroIndex = null;

    public function setMatchedParameters(array $parameters): static
    {
        $this->parameters = $parameters;
        $this->originalParameters = $parameters;

        return $this;
    }
}
