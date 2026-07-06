<?php

namespace Nitro\Htmx\Attributes;

use Attribute;

/**
 * Declares that an action's response body is a single property value,
 * not the rendered view. The framework reads $this->{$property} after
 * the action runs and writes it to the response as plain HTML text.
 *
 *   #[RenderValue('count')]
 *   public function increment(): void { $this->count++; }
 *
 * Equivalent to ending the action with $this->value($this->count). The
 * attribute form keeps the body focused on the state change and moves
 * the "what gets sent" decision to the signature, where readers look
 * first.
 *
 * Pair with hx-target / hx-swap on the trigger element so the scalar
 * lands inside the component, not on top of it:
 *
 *   <button hx-click="increment" hx-target="#count-display" hx-swap="innerHTML">+</button>
 *
 * An explicit $this->render() / $this->value() call inside the action
 * takes precedence — the attribute is the fallback when the action
 * doesn't speak up.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RenderValue
{
    public function __construct(public readonly string $property) {}
}
