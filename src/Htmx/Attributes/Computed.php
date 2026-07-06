<?php

namespace Nitro\Htmx\Attributes;

use Attribute;

/**
 * Marks a public no-arg method as a computed view-data value. The
 * framework invokes the method during viewData() resolution and merges
 * the return value into the view under the method's name.
 *
 *   class Counter extends HtmxComponent {
 *       public int $count = 0;
 *
 *       #[Computed]
 *       public function isEven(): bool {
 *           return $this->count % 2 === 0;
 *       }
 *   }
 *
 *   {{-- in the view --}}
 *   @if ($isEven) Even @else Odd @endif
 *
 * Computed values take precedence over reflected properties (so a
 * computed isEven() shadows a $isEven property) and are themselves
 * overridden by with() — public props < computed < with().
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Computed
{
}
