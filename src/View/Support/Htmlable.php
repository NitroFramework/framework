<?php

namespace Nitro\View\Support;

/**
 * Contract for objects that can render themselves to an HTML string.
 */
interface Htmlable
{
    public function toHtml(): string;
}
