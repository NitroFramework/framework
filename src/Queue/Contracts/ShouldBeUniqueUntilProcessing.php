<?php

namespace Nitro\Queue\Contracts;

/**
 * A unique job whose claim is released when it starts, not when it finishes.
 *
 * Use this when a second dispatch should be allowed to queue up behind the
 * one already running, rather than being dropped.
 */
interface ShouldBeUniqueUntilProcessing extends ShouldBeUnique
{
}
