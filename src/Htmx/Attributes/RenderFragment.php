<?php

namespace Nitro\Htmx\Attributes;

use Attribute;

/**
 * Declares that an action renders a specific @fragment block from the
 * component's default view, instead of the full view.
 *
 *   #[RenderFragment('counter')]
 *   public function reset(): void { $this->count = 0; }
 *
 * Equivalent to ending the action with $this->only('counter'). Use when
 * the response should swap a self-contained slice of the view rather
 * than the whole component.
 *
 * An explicit $this->render() / $this->only() call inside the action
 * takes precedence — the attribute is the fallback when the action
 * doesn't speak up.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RenderFragment
{
    public function __construct(public readonly string $name) {}
}
