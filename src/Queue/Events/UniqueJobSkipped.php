<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\Job;

/**
 * A unique job was dropped because one of its kind was already queued.
 *
 * Dispatching a unique job twice succeeds quietly either way, so
 * without this there is nothing to distinguish "queued" from "silently
 * discarded" at the call site.
 */
class UniqueJobSkipped
{
    public function __construct(
        public Job $job,
    ) {}
}
