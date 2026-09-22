<?php

namespace Nitro\Inertia;

use Nitro\Http\Request;

/**
 * What a prop provider is told about the render it is contributing to.
 *
 * Passed rather than left to be looked up, so a provider can answer
 * differently per page — returning a fuller set of props for a detail view
 * than for the list it was reached from — without resolving anything itself.
 */
class RenderContext
{
    public function __construct(
        public readonly string $component,
        public readonly Request $request,
    ) {
    }
}
