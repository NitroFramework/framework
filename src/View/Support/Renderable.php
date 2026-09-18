<?php

namespace Nitro\View\Support;

/**
 * Contract for objects that can render themselves to a string.
 */
interface Renderable
{
    /** Render the object to a string. */
    public function render(): string;
}
