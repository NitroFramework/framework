<?php

namespace Nitro\Livewire\Attributes;

use Attribute;

/**
 * Marks a component method as a computed property: accessed as $this->name (no
 * parentheses) in the component and its view, and memoized for the request.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Computed
{
}
