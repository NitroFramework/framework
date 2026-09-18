<?php

namespace Nitro\View\Support;

/**
 * Contract for objects that can render themselves to an HTML string.
 */
interface Htmlable
{
    /** Get the object's HTML representation. */
    public function toHtml(): string;
}
