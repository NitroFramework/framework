<?php

namespace Nitro\Livewire;

use Nitro\View\Support\Htmlable;
use Stringable;

/**
 * A chunk of slot content passed into a component from its parent. It is
 * Htmlable so {{ $slot }} / {{ $slots['name'] }} render the captured HTML
 * untouched (no escaping) in the child's view.
 */
class Slot implements Htmlable, Stringable
{
    public function __construct(protected string $html = '') {}

    public function toHtml(): string
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }

    /** Whether the slot has no meaningful content. */
    public function isEmpty(): bool
    {
        return trim($this->html) === '';
    }

    /** Whether the slot has content. */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }
}
