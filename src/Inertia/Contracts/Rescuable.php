<?php

namespace Nitro\Inertia\Contracts;

/**
 * A prop whose failure should not take the response with it.
 *
 * A deferred prop is resolved in a request of its own, after the page is
 * already on screen. Letting that request throw replaces a rendered page with
 * an error, for a value the page was designed to arrive without — so a
 * rescued prop reports itself as failed and leaves the rest standing.
 */
interface Rescuable
{
    public function shouldRescue(): bool;
}
