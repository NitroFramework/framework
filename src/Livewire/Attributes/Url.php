<?php

namespace Nitro\Livewire\Attributes;

use Attribute;

/**
 * Binds a public property to the browser's query string. On mount the property
 * is seeded from the current query string; whenever it changes the client keeps
 * the URL in sync (so the state is shareable and bookmarkable). Use `as` to
 * expose the property under a different query key, and `history` to push a new
 * history entry (rather than replace) on each change.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Url
{
    public function __construct(
        public ?string $as = null,
        public bool $history = false,
        public bool $keep = false,
    ) {}
}
