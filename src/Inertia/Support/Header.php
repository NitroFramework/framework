<?php

namespace Nitro\Inertia\Support;

/**
 * The headers the Inertia protocol is carried in.
 *
 * Both ends of the protocol agree on these names, so they are fixed: the
 * client library sends them and this layer answers to them. They are gathered
 * here rather than written inline so a typo cannot silently turn a partial
 * reload into a full one.
 */
final class Header
{
    /** Present on every request the client library makes. */
    public const INERTIA = 'X-Inertia';

    /** Names the error bag validation failures should be reported under. */
    public const ERROR_BAG = 'X-Inertia-Error-Bag';

    /** Tells the client to leave the app and load a URL normally. */
    public const LOCATION = 'X-Inertia-Location';

    /** Carries a redirect whose target has a URL fragment. */
    public const REDIRECT = 'X-Inertia-Redirect';

    /** The asset version the client currently holds. */
    public const VERSION = 'X-Inertia-Version';

    /** The component a partial reload is asking about. */
    public const PARTIAL_COMPONENT = 'X-Inertia-Partial-Component';

    /** The only props a partial reload wants. */
    public const PARTIAL_ONLY = 'X-Inertia-Partial-Data';

    /** The props a partial reload does not want. */
    public const PARTIAL_EXCEPT = 'X-Inertia-Partial-Except';

    /** Props whose merge should start from scratch rather than append. */
    public const RESET = 'X-Inertia-Reset';

    /** Once-props the client already holds and does not need again. */
    public const EXCEPT_ONCE_PROPS = 'X-Inertia-Except-Once-Props';

    /**
     * Which end of the list an infinite scroll is extending.
     *
     * Sent by the client because only it knows: scrolling down appends the
     * next page, scrolling up prepends the previous one, and the same
     * endpoint serves both.
     */
    public const INFINITE_SCROLL_MERGE_INTENT = 'X-Inertia-Infinite-Scroll-Merge-Intent';
}
