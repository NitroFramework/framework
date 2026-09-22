<?php

namespace Nitro\Events\Contracts;

/**
 * A listener that waits for the transaction the event was raised inside.
 *
 * The same rule as {@see ShouldDispatchAfterCommit}, decided by the
 * listener rather than the event — which is what you want when the
 * event is raised from somewhere you do not own, or when only one of
 * its listeners has to wait.
 */
interface ShouldHandleEventsAfterCommit
{
}
