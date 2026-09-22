<?php

namespace Nitro\Inertia;

use Nitro\Facades\Inertia;
use Nitro\Http\Request;

/**
 * The handler behind a route that renders a component and nothing else.
 *
 * A page with no logic — an about page, a static form — still needs a route,
 * and writing a controller whose whole body is one render() call is noise.
 * The route carries the component name and props as defaults, and this reads
 * them back.
 *
 *     Route::inertia('/about', 'About', ['version' => '1.0']);
 */
class Controller
{
    /**
     * @param array<string, mixed> $props
     */
    public function __invoke(Request $request, string $component = '', array $props = []): Response
    {
        return Inertia::render($component, $props);
    }
}
