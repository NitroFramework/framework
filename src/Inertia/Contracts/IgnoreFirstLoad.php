<?php

namespace Nitro\Inertia\Contracts;

/**
 * A prop that is left out of a page's first render.
 *
 * The page arrives without it and asks for it afterwards, which is what makes
 * an expensive value — a report, a count over a large table — not hold up the
 * first paint.
 */
interface IgnoreFirstLoad
{
}
