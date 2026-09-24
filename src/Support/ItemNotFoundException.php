<?php

namespace Nitro\Support;

use RuntimeException;

/**
 * Nothing matched where something was required to.
 *
 * Its own type so it can be caught apart from {@see MultipleItemsFoundException}:
 * "the row is gone" usually means a 404, where "there are two of them" means a
 * broken assumption about uniqueness. Both come out of sole(), and telling them
 * apart is the reason to call sole() rather than first().
 */
class ItemNotFoundException extends RuntimeException
{
}
