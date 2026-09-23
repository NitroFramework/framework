<?php

namespace Nitro\Events\Contracts;

/**
 * A listener that waits for the transaction the event was raised inside.
 *
 * The same rule as {@see ShouldDispatchAfterCommit}, decided by the
 * listener rather than the event.
 */
interface ShouldHandleEventsAfterCommit
{
}
