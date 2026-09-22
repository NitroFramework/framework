<?php

namespace Nitro\Inertia\Contracts;

/**
 * A prop the client fetches in a follow-up request, in a named group.
 *
 * Grouping matters because the client makes one request per group: props that
 * share a group arrive together, and props in different groups arrive
 * independently.
 */
interface Deferrable
{
    public function shouldDefer(): bool;

    /** The group this prop is fetched with. */
    public function group(): string;
}
