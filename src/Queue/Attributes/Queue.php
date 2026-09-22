<?php

namespace Nitro\Queue\Attributes;

use Attribute;
use UnitEnum;

/** The named queue this job runs on. */
#[Attribute(Attribute::TARGET_CLASS)]
class Queue
{
    public string $queue;

    public function __construct(UnitEnum|string $queue)
    {
        $this->queue = $queue instanceof UnitEnum
            ? (string) ($queue->value ?? $queue->name)
            : $queue;
    }
}
