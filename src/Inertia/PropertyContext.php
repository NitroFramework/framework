<?php

namespace Nitro\Inertia;

use Nitro\Http\Request;

/**
 * What a single-prop provider is told about the prop it is standing in for.
 *
 * Carries the key it was given, the props around it and the request, so an
 * object can decide its own value from where it was placed — a permission
 * check that depends on a sibling prop, say — rather than being resolved in
 * isolation.
 */
class PropertyContext
{
    /**
     * @param array<string, mixed> $props
     */
    public function __construct(
        public readonly string $key,
        public readonly array $props,
        public readonly Request $request,
    ) {
    }
}
