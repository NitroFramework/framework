<?php

namespace Nitro\View\Support;

/**
 * A string already escaped as HTML, echoed as-is by the compiler.
 */
class HtmlString implements Htmlable, \Stringable
{
    /** @param string $html Markup that is already safe to echo. */
    public function __construct(private string $html = '') {}

    /** Get the markup. */
    public function __toString(): string
    {
        return $this->html;
    }

    /** Get the markup. */
    public function toHtml(): string
    {
        return $this->html;
    }

    /** Determine whether the markup is empty or only whitespace. */
    public function isEmpty(): bool
    {
        return trim($this->html) === '';
    }

    /** Determine whether the markup holds anything. */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }
}
