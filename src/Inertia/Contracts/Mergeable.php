<?php

namespace Nitro\Inertia\Contracts;

/**
 * A prop whose new value is combined with what the client already holds,
 * instead of replacing it.
 *
 * This is what makes "load more" work: page two of a list is appended to page
 * one rather than overwriting it.
 */
interface Mergeable
{
    /** @return static */
    public function merge();

    /** @return static */
    public function deepMerge();

    public function shouldMerge(): bool;

    public function shouldDeepMerge(): bool;

    /**
     * Keys identifying a row, so a re-sent item replaces its earlier copy
     * rather than appearing twice.
     *
     * @return array<int, string>
     */
    public function matchesOn(): array;

    /** New values join at the end of the prop itself. */
    public function appendsAtRoot(): bool;

    /** New values join at the beginning of the prop itself. */
    public function prependsAtRoot(): bool;

    /**
     * Nested paths whose lists are extended at the end.
     *
     * @return array<int, string>
     */
    public function appendsAtPaths(): array;

    /**
     * Nested paths whose lists are extended at the beginning.
     *
     * @return array<int, string>
     */
    public function prependsAtPaths(): array;
}
