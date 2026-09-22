<?php

namespace Nitro\Events\Contracts;

/**
 * An event that waits for the transaction it was raised inside.
 *
 * Marked on the event itself: an OrderPlaced raised mid-transaction has
 * not really happened yet, and a listener that emails a receipt for an
 * order that then rolled back has sent something that is not true. The
 * listener cannot see the transaction, so the event has to say.
 *
 * Outside a transaction, or with nothing tracking one, it dispatches
 * immediately — there is nothing to wait for.
 */
interface ShouldDispatchAfterCommit
{
}
