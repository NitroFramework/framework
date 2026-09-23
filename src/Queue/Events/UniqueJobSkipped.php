<?php

namespace Nitro\Queue\Events;

use Nitro\Queue\Job;

/**
 * A unique job was dropped because one of its kind was already queued.
 */
class UniqueJobSkipped
{
    public function __construct(
        public Job $job,
    ) {}
}
