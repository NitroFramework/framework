<?php

namespace Nitro\Queue\Attributes;

use Attribute;

/**
 * Attempts this job may have before it is failed.
 *
 * The same thing as `protected int $tries`, written where a reader
 * looking for the job's terms will see it — above the class rather
 * than among its properties. A property set on the class still wins.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Tries
{
    public function __construct(public int $tries) {}
}
