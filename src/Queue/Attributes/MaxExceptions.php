<?php

namespace Nitro\Queue\Attributes;

use Attribute;

/** Throws this job may have before it is failed, whatever its attempts. */
#[Attribute(Attribute::TARGET_CLASS)]
class MaxExceptions
{
    public function __construct(public int $maxExceptions) {}
}
