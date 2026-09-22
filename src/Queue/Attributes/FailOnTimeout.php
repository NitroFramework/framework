<?php

namespace Nitro\Queue\Attributes;

use Attribute;

/**
 * Fail this job when it hits its timeout, rather than retrying it.
 *
 * Carries no value: the attribute being present is the instruction.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class FailOnTimeout
{
}
