<?php

namespace Nitro\Livewire\Attributes;

use Attribute;

/**
 * Marks a component for lazy loading: its first paint renders only a
 * placeholder (the component's placeholder() method, or a default skeleton),
 * and the real mount() + render runs in a follow-up request the client fires as
 * soon as the placeholder is in the DOM. Keeps heavy components off the initial
 * page render.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Lazy
{
    public function __construct(
        public bool $isolate = true,
    ) {}
}
