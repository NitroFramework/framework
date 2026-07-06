<?php

namespace Nitro\Livewire\Attributes;

use Attribute;

/**
 * Declares the layout a full-page component renders inside, e.g.
 * #[Layout('layouts.app')]. The component's HTML is injected into the layout's
 * given section (default 'content').
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Layout
{
    public function __construct(
        public string $layout,
        public string $section = 'content',
    ) {}
}
