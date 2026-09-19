<?php

namespace Nitro\View\Events;

/**
 * Events the view layer raises.
 *
 * Both fire for every template a page touches, partials and components
 * included, so a single page raises them many times over. Both carry a
 * {@see ViewEvent}, whose renderCount says how deep the template is.
 */
class ViewEvents
{
    /** Before a template is compiled and executed. */
    const RENDERING = 'view.rendering';

    /** After it produced its markup. A template that threw raises only the first. */
    const RENDERED = 'view.rendered';
}
