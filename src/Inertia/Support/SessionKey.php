<?php

namespace Nitro\Inertia\Support;

/**
 * Session keys this layer writes under.
 *
 * Namespaced so a flash message the application sets cannot collide with one
 * the protocol needs.
 */
final class SessionKey
{
    /** Set when the next page should drop the client's history state. */
    public const CLEAR_HISTORY = 'inertia.clear_history';

    /** Flash data travelling to the next Inertia response. */
    public const FLASH_DATA = 'inertia.flash_data';

    /** Set when a redirect should keep the URL fragment it arrived with. */
    public const PRESERVE_FRAGMENT = 'inertia.preserve_fragment';
}
