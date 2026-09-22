<?php

namespace Nitro\Inertia\Contracts;

/**
 * Where an infinite scroll is in a sequence.
 *
 * The client needs three things to keep scrolling: what to ask for next, what
 * to ask for going back, and which parameter carries it. A source that is not
 * a paginator supplies these itself.
 */
interface ProvidesScrollMetadata
{
    /** The query-string parameter the page is requested by. */
    public function getPageName(): string;

    public function getPreviousPage(): int|string|null;

    public function getNextPage(): int|string|null;

    public function getCurrentPage(): int|string|null;
}
