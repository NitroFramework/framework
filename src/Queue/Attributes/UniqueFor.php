<?php

namespace Nitro\Queue\Attributes;

use Attribute;

/**
 * Seconds a unique job's claim is held.
 *
 * The claim lapses on its own after this, so a worker that died
 * holding one does not keep the job from ever being queued again.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UniqueFor
{
    public function __construct(public int $uniqueFor) {}
}
