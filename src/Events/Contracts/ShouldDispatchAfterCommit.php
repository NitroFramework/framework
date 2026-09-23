<?php

namespace Nitro\Events\Contracts;

/**
 * An event that waits for the transaction it was raised inside.
 *
 * Outside a transaction it dispatches immediately.
 */
interface ShouldDispatchAfterCommit
{
}
