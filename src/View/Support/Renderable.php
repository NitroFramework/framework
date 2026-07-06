<?php

namespace Nitro\View\Support;

/**
 * Contract for objects that can render themselves to a string.
 */
interface Renderable
{
    public function render(): string;
}
