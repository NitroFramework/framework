<?php

namespace Nitro\Queue\Attributes;

use Attribute;

/** Seconds before this job becomes eligible to run. */
#[Attribute(Attribute::TARGET_CLASS)]
class Delay
{
    public function __construct(public int $delay) {}
}
