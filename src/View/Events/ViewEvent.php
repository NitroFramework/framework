<?php

namespace Nitro\View\Events;

/**
 * Payload for view.rendering and view.rendered.
 *
 * Both fire for every template a page touches, partials and components
 * included, so renderCount is what tells them apart: 0 is the page itself and
 * anything higher is something it pulled in. A listener counting renders per
 * request should watch for 0 to know a new page started.
 */
class ViewEvent
{
    /**
     * @param string   $view        The view name, as the caller asked for it.
     * @param int      $renderCount Nesting depth; 0 is the top-level page.
     * @param int|null $length      Bytes of markup produced, on view.rendered only.
     */
    public function __construct(
        public readonly string $view,
        public readonly int $renderCount = 0,
        public readonly ?int $length = null,
    ) {}
}
