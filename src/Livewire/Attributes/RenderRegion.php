<?php

namespace Nitro\Livewire\Attributes;

use Attribute;

/**
 * Declares that an action re-renders only a named region of the component,
 * instead of the whole component. A region is an @region('name') /
 * wire:region="name" block of the view; the client patches just that region.
 *
 *   #[RenderRegion('list')]
 *   public function delete(int $id): void { Student::find($id)?->delete(); }
 *
 * Equivalent to ending the action with $this->renderRegion('list'). An explicit
 * renderRegion() call inside the action takes precedence. Regions are a partial
 * re-render of the full (component-bound) render — distinct from islands, which
 * are isolated and skip re-rendering by default.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RenderRegion
{
    public function __construct(public readonly string $name) {}
}
