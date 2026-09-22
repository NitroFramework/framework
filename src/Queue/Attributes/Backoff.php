<?php

namespace Nitro\Queue\Attributes;

use Attribute;

/**
 * Seconds to wait between attempts.
 *
 * Several values are a per-attempt schedule — #[Backoff(1, 10, 60)]
 * waits a second before the second attempt, ten before the third, and
 * a minute before every attempt after that.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Backoff
{
    /** @var int|array<int, int> */
    public int|array $backoff;

    public function __construct(array|int ...$backoff)
    {
        $this->backoff = count($backoff) === 1 ? $backoff[0] : $backoff;
    }
}
