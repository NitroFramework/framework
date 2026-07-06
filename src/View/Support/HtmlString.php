<?php

namespace Nitro\View\Support;

/**
 * A string already escaped as HTML, echoed as-is by the compiler.
 */
class HtmlString implements Htmlable, \Stringable
{
    public function __construct(private string $html = '') {}

    public function __toString(): string
    {
        return $this->html;
    }

    public function toHtml(): string
    {
        return $this->html;
    }

    public function isEmpty(): bool
    {
        return trim($this->html) === '';
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }
}
