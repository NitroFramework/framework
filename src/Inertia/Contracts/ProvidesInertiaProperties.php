<?php

namespace Nitro\Inertia\Contracts;

use Nitro\Inertia\RenderContext;

/**
 * An object that contributes a set of props rather than being one.
 *
 * Passed to render() without a key, and its props are merged in as though the
 * controller had written them out. This is how a group of related props — a
 * user with their permissions and preferences — travels as one thing and
 * stays consistent, instead of being assembled by hand at each call site.
 */
interface ProvidesInertiaProperties
{
    /**
     * @return iterable<string, mixed>
     */
    public function toInertiaProperties(RenderContext $context): iterable;
}
