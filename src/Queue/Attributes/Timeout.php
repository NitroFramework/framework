<?php

namespace Nitro\Queue\Attributes;

use Attribute;

/** Seconds one attempt at this job may run before the worker kills it. */
#[Attribute(Attribute::TARGET_CLASS)]
class Timeout
{
    public function __construct(public int $timeout) {}
}
